<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Privacy;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataErasedEvent;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataExporterInterface;
use Nowo\HttpLogBundle\Privacy\UserKit\HttpLogUserKitExportProvider;
use Nowo\HttpLogBundle\Privacy\UserKit\HttpLogUserKitPersonalDataEraser;
use Nowo\HttpLogBundle\Tests\Kernel\PersonalDataEraseTestController;
use Nowo\HttpLogBundle\Tests\Kernel\UsageKernel;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_slice;

/**
 * GDPR ports (1.18) in a booted kernel: the public ports autowire (event dispatcher included), and the UserKit eraser
 * and export section work against the real services.
 */
final class PersonalDataKernelTest extends TestCase
{
    #[Test]
    public function theUserKitBridgeErasesAndExportsThroughTheContainer(): void
    {
        $kernel = new UsageKernel(['async' => false, 'personal_data' => ['enabled' => true, 'export_limit' => 1]], [], static function (ContainerBuilder $container): void {
            $container->setAlias('test.http_log.user_kit_eraser', HttpLogUserKitPersonalDataEraser::class)->setPublic(true);
            $container->setAlias('test.http_log.user_kit_export', HttpLogUserKitExportProvider::class)->setPublic(true);
        }, 'gdpr-ports');
        $kernel->boot();

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $em = $container->get('doctrine.orm.entity_manager');
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            (new SchemaTool($em))->createSchema([$em->getClassMetadata(HttpLogEntry::class)]);
            foreach (['ana', 'ana', 'luis'] as $i => $user) {
                $em->persist((new HttpLogEntry())->setUserIdentifier($user)->setClientIp('10.0.0.' . $i)->setCreatedAt(new DateTimeImmutable('2026-01-0' . ($i + 1))));
            }
            $em->flush();
            $em->clear();

            $exporter = $container->get(HttpLogPersonalDataExporterInterface::class);
            self::assertInstanceOf(HttpLogPersonalDataExporterInterface::class, $exporter);
            $provider = $container->get('test.http_log.user_kit_export');
            self::assertInstanceOf(HttpLogUserKitExportProvider::class, $provider);
            $section = $provider->export(new InMemoryUser('ana', null));
            self::assertSame(2, $section[0]['total']);
            self::assertSame(1, $section[0]['limit'], 'personal_data.export_limit');

            $events     = [];
            $dispatcher = $container->get('event_dispatcher');
            self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
            $dispatcher->addListener(HttpLogPersonalDataErasedEvent::class, static function (HttpLogPersonalDataErasedEvent $event) use (&$events): void {
                $events[] = $event;
            });
            $eraser = $container->get('test.http_log.user_kit_eraser');
            self::assertInstanceOf(HttpLogUserKitPersonalDataEraser::class, $eraser);
            $eraser->erase(new ErasureRequest(new InMemoryUser('anonymised-1', null), null, [], 'ana'));

            self::assertCount(1, $events);
            self::assertSame(['entries' => 2], $events[0]->counts);
            self::assertSame(['luis'], $em->getConnection()->fetchFirstColumn('SELECT user_identifier FROM nowo_http_log_entry'));
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * LY-L2 in a kept-alive kernel (no services resetter between requests): the request that erases a client IP is not
     * written again at kernel.terminate, and the skip list does not leak into the next requests of the same kernel.
     */
    #[Test]
    public function theErasingRequestIsNotLoggedAndTheSkipListDoesNotLeak(): void
    {
        $kernel = new UsageKernel(['async' => false], [], static function (ContainerBuilder $container): void {
            $container->register(PersonalDataEraseTestController::class)->setAutowired(true)->setPublic(true)->addTag('controller.service_arguments');
        }, 'gdpr-ly-l2');
        $kernel->boot();

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $em = $container->get('doctrine.orm.entity_manager');
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            (new SchemaTool($em))->createSchema([$em->getClassMetadata(HttpLogEntry::class)]);
            $httpKernel = $container->get('http_kernel');
            self::assertInstanceOf(HttpKernelInterface::class, $httpKernel);
            self::assertInstanceOf(TerminableInterface::class, $httpKernel);
            $run = static function (string $path, string $ip) use ($httpKernel): string {
                $request  = Request::create($path, 'GET', server: ['REMOTE_ADDR' => $ip]);
                $response = $httpKernel->handle($request);
                $httpKernel->terminate($request, $response);

                return (string) $response->getContent();
            };
            $rows = static fn (): array => $em->getConnection()->fetchAllAssociative('SELECT path, client_ip FROM nowo_http_log_entry ORDER BY id');

            for ($round = 0; $round < 50; ++$round) {
                $run('/hello', '10.0.0.9');
                $run('/hello', '10.0.0.8');
                self::assertSame('1', $run('/gdpr/erase-my-ip', '10.0.0.9'), 'the earlier entry is erased');
                self::assertSame([['path' => '/hello', 'client_ip' => '10.0.0.8']], array_slice($rows(), -1), 'the erasing request itself is not logged');
                $run('/hello', '10.0.0.9');
                self::assertSame(['path' => '/hello', 'client_ip' => '10.0.0.9'], array_slice($rows(), -1)[0], 'the next request of the same IP is logged again: no leak');
                $em->getConnection()->executeStatement('DELETE FROM nowo_http_log_entry');
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
