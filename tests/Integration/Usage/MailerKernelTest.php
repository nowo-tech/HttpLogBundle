<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Usage;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Mailer\MailerCaptureListener;
use Nowo\HttpLogBundle\Tests\Kernel\PublicServicesPass;
use Nowo\HttpLogBundle\Tests\Kernel\UsageKernel;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mime\Email;

/**
 * HL-1 S3 on a booted kernel: a mail queued through Messenger is one row, written when the handler sends it, with the
 * tenant / project of the dispatcher and the stable message id of the context stamp.
 */
final class MailerKernelTest extends TestCase
{
    #[Test]
    public function aQueuedMailCarriesTheDispatcherContext(): void
    {
        $kernel = new UsageKernel(
            ['async' => false, 'mailer' => ['enabled' => true, 'providers' => ['local' => ['schemes' => ['null']]]]],
            [
                'mailer'    => ['dsn' => 'null://null'],
                'messenger' => [
                    'transports' => ['sync' => 'sync://'],
                    'routing'    => [SendEmailMessage::class => 'sync'],
                    'buses'      => ['messenger.bus.default' => ['middleware' => [NowoHttpLogExtension::CONTEXT_MIDDLEWARE]]],
                ],
            ],
            static function (ContainerBuilder $container): void {
                $container->addCompilerPass(new PublicServicesPass(['mailer.mailer']), PassConfig::TYPE_BEFORE_REMOVING);
            },
            'mailer',
        );
        $kernel->boot();

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            self::assertTrue($container->has(MailerCaptureListener::class));
            self::assertTrue($container->has(NowoHttpLogExtension::CONTEXT_MIDDLEWARE), 'used by a bus: kept');
            $em = $container->get('doctrine.orm.entity_manager');
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            (new SchemaTool($em))->createSchema([$em->getClassMetadata(HttpLogEntry::class)]);

            $mailer  = $container->get('mailer.mailer');
            $context = $container->get(HttpLogContext::class);
            self::assertInstanceOf(MailerInterface::class, $mailer);
            self::assertInstanceOf(HttpLogContext::class, $context);
            $context->run('org-1', static fn () => $mailer->send((new Email())->from('a@mail.test')->to('b@mail.test')->subject('x')->text('y')), 'project-1');

            $buffer = $container->get(HttpLogBuffer::class);
            self::assertInstanceOf(HttpLogBuffer::class, $buffer);
            $buffer->flush();

            $rows = $em->getConnection()->fetchAllAssociative('SELECT channel, provider, scheme, tenant_key, project_key, messenger_message_id, external_message_id FROM nowo_http_log_entry');
            self::assertCount(1, $rows);
            self::assertSame(['mailer', 'local', 'null', 'org-1', 'project-1'], [$rows[0]['channel'], $rows[0]['provider'], $rows[0]['scheme'], $rows[0]['tenant_key'], $rows[0]['project_key']]);
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $rows[0]['messenger_message_id']);
            self::assertNotNull($rows[0]['external_message_id']);
        } finally {
            $kernel->shutdown();
        }
    }
}
