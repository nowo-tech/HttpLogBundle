<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Usage;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\Context\HttpLogContextInterface;
use Nowo\HttpLogBundle\Event\HttpLogEntryRecordedEvent;
use Nowo\HttpLogBundle\Tests\Kernel\ApiTestController;
use Nowo\HttpLogBundle\Tests\Kernel\DddHostContextResolver;
use Nowo\HttpLogBundle\Tests\Kernel\DddHostEntryListener;
use Nowo\HttpLogBundle\Tests\Kernel\UsageKernel;
use Nowo\HttpLogBundle\Tests\Kernel\UsageMockResponses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

use function array_column;
use function array_map;
use function gc_collect_cycles;
use function memory_get_usage;
use function sprintf;
use function strval;

/**
 * DDD host path of REQ-ARCH-001 (rules 1, 3 and 4) on a booted kernel: the host's request-context resolver implements
 * ONLY the framework-free `HttpRequestContextResolverInterface` (no `Request`, no `TokenInterface`), its code depends on
 * `HttpLogContextInterface`, and it reacts to `HttpLogEntryRecordedEvent` (ids only). The bundle owns its table; the
 * host has no entity, mapping or base class to provide.
 *
 * One kernel serves alternating tenants with the services resetter never called (kernel reset false, REQ-RUNTIME-002):
 * every row and every event holds only its own request's tenant and credential, and memory stays flat.
 */
final class DddHostKernelTest extends TestCase
{
    private const ITERATIONS = 600;

    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    private const CONFIG = [
        'async'                => false,
        'sampling_rate'        => 1.0,
        'ignore_path_prefixes' => ['/api'],
        'channels'             => ['api' => ['path_prefixes' => ['/api/']]],
    ];

    #[Test]
    public function aHostWithOnlyTheFrameworkFreePortsRecordsAndIsNotified(): void
    {
        [$kernel, $container] = $this->boot();

        try {
            $context = $container->get(HttpLogContextInterface::class);
            self::assertInstanceOf(HttpLogContext::class, $context);
            self::assertSame($container->get(HttpLogContext::class), $context, 'the interface is an alias of the one context');

            $this->request($kernel, '/api/tasks/42', ['HTTP_AUTHORIZATION' => 'Bearer SEED14placeholder', 'HTTP_X_TEST_ORG' => 'org-a']);
            $this->request($kernel, '/hello');

            $rows = $this->em($container)->getConnection()->fetchAllAssociative('SELECT request_id, channel, credential_kind, credential_id, tenant_key, project_key FROM nowo_http_log_entry ORDER BY id');
            self::assertSame(['api', null], array_column($rows, 'channel'));
            self::assertSame(['personal_token', 'pt-42', 'org-a', 'project-org-a'], [$rows[0]['credential_kind'], $rows[0]['credential_id'], $rows[0]['tenant_key'], $rows[0]['project_key']]);

            $resolver = $container->get(DddHostContextResolver::class);
            self::assertInstanceOf(DddHostContextResolver::class, $resolver);
            self::assertSame([['channel' => 'api', 'route' => 'api_task', 'authorization' => null, 'org' => 'org-a']], $resolver->seen, 'once, without the credential');

            $listener = $container->get(DddHostEntryListener::class);
            self::assertInstanceOf(DddHostEntryListener::class, $listener);
            self::assertSame([
                ['channel' => 'api', 'request_id' => $rows[0]['request_id'], 'tenant' => 'org-a', 'credential' => 'pt-42', 'status' => 200],
                ['channel' => HttpLogEntryRecordedEvent::CHANNEL_WEB, 'request_id' => $rows[1]['request_id'], 'tenant' => null, 'credential' => null, 'status' => 200],
            ], $listener->recorded);
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    public function alternatingTenantsNeverLeakWithTheResetterOff(): void
    {
        [$kernel, $container] = $this->boot();

        try {
            $listener = $container->get(DddHostEntryListener::class);
            self::assertInstanceOf(DddHostEntryListener::class, $listener);
            $context = $container->get(HttpLogContextInterface::class);
            self::assertInstanceOf(HttpLogContext::class, $context);

            $memory = 0;
            for ($i = 0; $i < self::ITERATIONS; ++$i) {
                // even: org-a with a credential; odd: org-b, or no tenant at all every fourth request
                $server = match ($i % 4) {
                    0, 2    => ['HTTP_X_TEST_ORG' => 'org-a', 'HTTP_AUTHORIZATION' => 'Bearer SEED14placeholder'],
                    1       => ['HTTP_X_TEST_ORG' => 'org-b'],
                    default => [],
                };
                $this->request($kernel, '/api/tasks/' . (1000 + $i), $server); // ids 1000+: never the test controller's 404
                self::assertSame(0, $context->depth(), 'the request frame is popped at terminate');
                if ($i === 100) {
                    gc_collect_cycles();
                    $memory = memory_get_usage();
                }
            }

            $rows = $this->em($container)->getConnection()->fetchAllAssociative('SELECT request_id, tenant_key, credential_id FROM nowo_http_log_entry ORDER BY id');
            self::assertCount(self::ITERATIONS, $rows);
            self::assertCount(self::ITERATIONS, $listener->recorded);
            foreach ($rows as $i => $row) {
                $tenant = match ($i % 4) {
                    0, 2    => 'org-a',
                    1       => 'org-b',
                    default => null,
                };
                self::assertSame($tenant, $row['tenant_key'], sprintf('row %d', $i));
                self::assertSame($tenant === null ? null : 'pt-' . (1000 + $i), $row['credential_id'], sprintf('row %d', $i));
                self::assertSame([$row['request_id'], $tenant, $row['credential_id']], [$listener->recorded[$i]['request_id'], $listener->recorded[$i]['tenant'], $listener->recorded[$i]['credential']], sprintf('event %d', $i));
            }
            self::assertSame(array_map(strval(...), array_column($rows, 'request_id')), array_column($listener->recorded, 'request_id'));

            gc_collect_cycles();
            self::assertLessThan(self::MEMORY_MARGIN, memory_get_usage() - $memory, 'memory flat');
        } finally {
            $kernel->shutdown();
        }
    }

    /** @return array{0: UsageKernel, 1: ContainerInterface} */
    private function boot(): array
    {
        $kernel = new UsageKernel(self::CONFIG, ['http_client' => ['mock_response_factory' => UsageMockResponses::class]], static function (ContainerBuilder $container): void {
            $container->register(UsageMockResponses::class, UsageMockResponses::class)->setPublic(true);
            $container->register('logger', NullLogger::class)->setPublic(true);
            $container->register(DddHostContextResolver::class, DddHostContextResolver::class)
                ->setPublic(true)
                ->setAutoconfigured(true);
            $container->register(DddHostEntryListener::class, DddHostEntryListener::class)
                ->setPublic(true)
                ->addTag('kernel.event_listener', ['event' => HttpLogEntryRecordedEvent::class]);
            $container->register(ApiTestController::class, ApiTestController::class)
                ->setPublic(true)
                ->addTag('controller.service_arguments');
        }, 'ddd-host');
        $kernel->boot();
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $em = $this->em($container);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        return [$kernel, $container];
    }

    /** @param array<string, string> $server */
    private function request(UsageKernel $kernel, string $uri, array $server = []): void
    {
        $request  = Request::create($uri, 'GET', [], [], [], $server + ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $kernel->handle($request);
        self::assertSame(200, $response->getStatusCode(), $uri);
        $kernel->terminate($request, $response);
    }

    private function em(ContainerInterface $container): EntityManagerInterface
    {
        $em = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
