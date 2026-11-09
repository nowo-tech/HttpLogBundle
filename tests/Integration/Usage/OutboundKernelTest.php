<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Usage;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\HttpClient\HttpLogHttpClientWrapper;
use Nowo\HttpLogBundle\HttpClient\LoggingHttpClient;
use Nowo\HttpLogBundle\Tests\Kernel\PublicServicesPass;
use Nowo\HttpLogBundle\Tests\Kernel\UsageKernel;
use Nowo\HttpLogBundle\Tests\Kernel\UsageMockResponses;
use Nowo\HttpLogBundle\Tests\Kernel\UsageTracingClient;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function array_column;

/**
 * HL-1 S2 on a booted kernel: the decorating pass wraps `http_client` and the selected scoped clients outermost
 * (outside RetryableHttpClient and a tracing decorator), excluded clients are left alone.
 */
final class OutboundKernelTest extends TestCase
{
    private const FRAMEWORK = ['http_client' => [
        'mock_response_factory' => UsageMockResponses::class,
        'scoped_clients'        => [
            'clickup.client'  => ['base_uri' => 'https://api.clickup.com', 'retry_failed' => ['max_retries' => 2, 'delay' => 0, 'http_codes' => [500]]],
            'excluded.client' => ['base_uri' => 'https://excluded.example'],
        ],
    ]];

    #[Test]
    public function decoratedClientsRecordOneRowPerLogicalCall(): void
    {
        $kernel = new UsageKernel([
            'async'    => false,
            'outbound' => [
                'enabled'         => true,
                'exclude_clients' => ['excluded.client'],
                'providers'       => ['clickup' => ['clients' => ['clickup.client'], 'path_templates' => ['/api/v2/task/{id}']]],
            ],
        ], self::FRAMEWORK, static function (ContainerBuilder $container): void {
            $container->register(UsageMockResponses::class, UsageMockResponses::class)->setPublic(true);
            $container->addCompilerPass(new PublicServicesPass(['clickup.client', 'excluded.client']), PassConfig::TYPE_BEFORE_REMOVING);
            $container->register('test.tracer', UsageTracingClient::class)
                ->setDecoratedService('http_client', null, 100)
                ->setArguments([new Reference('test.tracer.inner')])
                ->setPublic(true);
        }, 'outbound');
        $kernel->boot();

        try {
            $container = $this->container($kernel);
            $em        = $container->get('doctrine.orm.entity_manager');
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            (new SchemaTool($em))->createSchema([$em->getClassMetadata(HttpLogEntry::class)]);

            $http = $container->get('http_client');
            self::assertInstanceOf(LoggingHttpClient::class, $http, 'outermost');
            self::assertInstanceOf(LoggingHttpClient::class, $container->get('clickup.client'));
            self::assertNotInstanceOf(LoggingHttpClient::class, $container->get('excluded.client'));

            $clickup = $container->get('clickup.client');
            self::assertInstanceOf(HttpClientInterface::class, $clickup);
            self::assertSame(200, $clickup->request('GET', '/api/v2/task/86abc')->getStatusCode());
            self::assertInstanceOf(HttpClientInterface::class, $http);
            $http->request('GET', 'https://unknown.example/a/b/c')->getContent();
            $excluded = $container->get('excluded.client');
            self::assertInstanceOf(HttpClientInterface::class, $excluded);
            $excluded->request('GET', '/x')->getContent();

            $tracer = $container->get('test.tracer');
            self::assertInstanceOf(UsageTracingClient::class, $tracer);
            self::assertSame(1, $tracer->calls, 'the tracing decorator still sees the call');

            $buffer = $container->get(HttpLogBuffer::class);
            self::assertInstanceOf(HttpLogBuffer::class, $buffer);
            $buffer->flush();

            $rows = $em->getConnection()->fetchAllAssociative('SELECT provider, path, status_code, retry_count FROM nowo_http_log_entry ORDER BY id');
            self::assertSame(['clickup', 'other'], array_column($rows, 'provider'));
            self::assertSame(['/api/v2/task/{id}', '/{seg}/{seg}/{…}'], array_column($rows, 'path'));
            self::assertSame(1, (int) $rows[0]['retry_count'], 'one row per logical call, after retries');
            self::assertSame(200, (int) $rows[0]['status_code']);

            $wrapper = $container->get(HttpLogHttpClientWrapper::class);
            self::assertInstanceOf(HttpLogHttpClientWrapper::class, $wrapper);
            self::assertInstanceOf(LoggingHttpClient::class, $wrapper->wrap(new MockHttpClient(), 's3'));
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    public function disabledOutboundLeavesTheClientsAndTheWrapperAlone(): void
    {
        $kernel = new UsageKernel([], self::FRAMEWORK, static function (ContainerBuilder $container): void {
            $container->register(UsageMockResponses::class, UsageMockResponses::class)->setPublic(true);
            $container->addCompilerPass(new PublicServicesPass(['clickup.client', 'excluded.client']), PassConfig::TYPE_BEFORE_REMOVING);
        }, 'outbound-off');
        $kernel->boot();

        try {
            $container = $this->container($kernel);
            self::assertNotInstanceOf(LoggingHttpClient::class, $container->get('http_client'));
            self::assertNotInstanceOf(LoggingHttpClient::class, $container->get('clickup.client'));

            $wrapper = $container->get(HttpLogHttpClientWrapper::class);
            self::assertInstanceOf(HttpLogHttpClientWrapper::class, $wrapper);
            $inner = new MockHttpClient();
            self::assertSame($inner, $wrapper->wrap($inner, 's3'));
        } finally {
            $kernel->shutdown();
        }
    }

    private function container(UsageKernel $kernel): ContainerInterface
    {
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }
}
