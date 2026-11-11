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
use Nowo\HttpLogBundle\Tests\Kernel\UsageKernel;
use Nowo\UserKitBundle\PersonalData\ErasureRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * GDPR ports (1.18) in a booted kernel: the public ports autowire (event dispatcher included), and the UserKit eraser
 * and export section work against the real services.
 */
final class PersonalDataKernelTest extends TestCase
{
    #[Test]
    public function theUserKitBridgeErasesAndExportsThroughTheContainer(): void
    {
        $kernel = new UsageKernel(['async' => false, 'personal_data' => ['export_limit' => 1]], [], static function (ContainerBuilder $container): void {
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
}
