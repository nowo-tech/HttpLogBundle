<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Usage;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\Inbound\InboundRequestRecorder;
use Nowo\HttpLogBundle\Inbound\InboundRequestSubscriber;
use Nowo\HttpLogBundle\Inbound\RouteTemplateMap;
use Nowo\HttpLogBundle\Rollup\RollupSchemaListener;
use Nowo\HttpLogBundle\Tests\Kernel\ApiTestController;
use Nowo\HttpLogBundle\Tests\Kernel\TestRequestContextResolver;
use Nowo\HttpLogBundle\Tests\Kernel\UsageKernel;
use Nowo\HttpLogBundle\Tests\Kernel\UsageMockResponses;
use Nowo\HttpLogBundle\Tests\Support\CollectingLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function array_column;
use function array_map;
use function base64_encode;
use function file_exists;
use function implode;
use function intdiv;
use function json_encode;
use function memory_get_usage;
use function preg_match;
use function rawurlencode;
use function sprintf;
use function str_repeat;
use function stripos;

use const JSON_THROW_ON_ERROR;

/**
 * HL-1 S4 on a booted kernel with a Beacon-shaped configuration: `/api`, `/hooks` and `/mcp` are ignored by the web
 * channel and recorded by named channels with their own sampling; errors are always kept; rows hold the route
 * template, a truncated IP and the guarded resolver context, never the raw path, query string, headers, bodies or the
 * client's X-Request-Id; outbound calls made while serving an API request inherit its tenant; nothing leaks across
 * 1 000 requests in one kernel.
 */
final class ApiChannelKernelTest extends TestCase
{
    /** Placeholder secrets of HL-1 §4.3 cases 11, 12 and 15 (never real credentials). */
    private const SEEDS = ['SEED14placeholder', 'SEED15placeholder', 'SEED16placeholder', 'SEED24placeholder'];

    private const CONFIG = [
        'async'                => false,
        'sampling_rate'        => 0.0,
        'ignore_path_prefixes' => ['/api', '/hooks', '/mcp'],
        'channels'             => [
            'api'      => ['path_prefixes' => ['/api/'], 'exclude_path_prefixes' => ['/api/ingest'], 'sampling_rate' => 0.0],
            'webhooks' => ['path_prefixes' => ['/hooks']],
            'mcp'      => ['routes' => ['MCP_*']],
        ],
        'rollup'   => ['enabled' => true],
        'outbound' => ['enabled' => true, 'providers' => ['clickup' => ['hosts' => ['api.clickup.com'], 'path_templates' => ['/api/v2/task/{id}']]]],
    ];

    #[Test]
    public function namedChannelsRecordBeforeTheWebIgnoresWithTheirOwnSampling(): void
    {
        [$kernel, $container] = $this->boot(self::CONFIG);

        try {
            $headers = ['HTTP_AUTHORIZATION' => 'Bearer SEED14placeholder', 'HTTP_X_REQUEST_ID' => 'SEED24placeholder', 'HTTP_X_TEST_ORG' => 'org-a', 'REMOTE_ADDR' => '203.0.113.77'];
            $this->request($kernel, '/api/tasks/42?token=SEED15placeholder', $headers);
            $this->request($kernel, '/api/tasks/404', $headers, 404);
            $this->request($kernel, '/api/tasks/fail', $headers, 500);
            $this->request($kernel, '/api/share/SEED16placeholder', $headers, 403);
            $this->request($kernel, '/api/nothing/here', $headers, 404);
            $this->request($kernel, '/api/ingest', $headers);
            $this->request($kernel, '/hooks/stripe', ['HTTP_X_TEST_ORG' => 'org-a'], 200, 'POST', '{"secret":"SEED14placeholder"}');
            $this->request($kernel, '/hello', $headers);

            $rows = $this->em($container)->getConnection()->fetchAllAssociative('SELECT * FROM nowo_http_log_entry ORDER BY id');
            self::assertSame(['api', 'api', 'api', 'api', 'webhooks'], array_column($rows, 'channel'), 'sampling 0.0 keeps only the errors; /api/ingest and /hello are web (ignored / sampled out)');
            self::assertSame(['/api/tasks/{id}', '/api/tasks/{id}', '/api/share/{token}', '{unrouted}', '/hooks/stripe'], array_column($rows, 'path'));
            self::assertSame([null, 'exception', null, 'exception', null], array_column($rows, 'error_kind'));
            self::assertSame(['404', '500', '403', '404', '200'], array_map(strval(...), array_column($rows, 'status_code')));
            self::assertSame(['api_task', 'api_task', 'api_share', null, 'hooks_stripe'], array_column($rows, 'route_name'));
            self::assertSame('203.0.113.0', $rows[0]['client_ip'], 'truncated to /24 by default');
            self::assertSame(['personal_token', 'pt-17', 'org-a', 'project-1'], [$rows[0]['credential_kind'], $rows[0]['credential_id'], $rows[0]['tenant_key'], $rows[0]['project_key']]);
            self::assertSame(['signature', 'stripe-endpoint-1'], [$rows[4]['credential_kind'], $rows[4]['credential_id']]);
            foreach ($rows as $row) {
                self::assertSame(1, preg_match('/^[0-9a-f]{32}$/', (string) $row['request_id']), 'server event id, never X-Request-Id');
                foreach (['query_params', 'request_headers', 'response_headers', 'request_body', 'response_body', 'body_content_type'] as $column) {
                    self::assertNull($row[$column], $column);
                }
            }
            $this->assertNoSeed($rows);

            // every api call is counted, sampled or not; /api/ingest (web) and /hello (web, rollup.web off) are not
            $buckets = $this->em($container)->getConnection()->fetchAllAssociative('SELECT channel, tenant_key, credential_id, calls, errors, rows_stored FROM nowo_http_log_daily ORDER BY channel, credential_id');
            self::assertSame([['api', 'org-a', 'pt-17', 5, 4, 4], ['webhooks', 'org-a', 'stripe-endpoint-1', 1, 0, 1]], array_map(static fn (array $b): array => [$b['channel'], $b['tenant_key'], $b['credential_id'], (int) $b['calls'], (int) $b['errors'], (int) $b['rows_stored']], $buckets));
            $this->assertNoSeed($buckets);

            $resolver = $container->get(TestRequestContextResolver::class);
            self::assertInstanceOf(TestRequestContextResolver::class, $resolver);
            self::assertSame(['api', 'api', 'api', 'api', 'api', 'webhooks'], $resolver->channels, 'once per named-channel request');
            self::assertFileExists($kernel->getCacheDir() . '/' . RouteTemplateMap::RELATIVE_PATH);
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    public function aResolverReturningTheBearerOrThrowingStoresNullAndNeverBreaksTheRequest(): void
    {
        foreach (['leak', 'throw'] as $mode) {
            [$kernel, $container, $logger] = $this->boot(['channels' => ['api' => ['path_prefixes' => ['/api']]]] + self::CONFIG, $mode);

            try {
                $response = $this->request($kernel, '/api/tasks/7', ['HTTP_AUTHORIZATION' => 'Bearer SEED14placeholder', 'HTTP_X_TEST_ORG' => 'org-a']);
                self::assertSame('{"id":"7"}', $response->getContent());

                $rows = $this->em($container)->getConnection()->fetchAllAssociative('SELECT credential_kind, credential_id, tenant_key FROM nowo_http_log_entry');
                self::assertSame($mode === 'leak' ? [['credential_kind' => 'personal_token', 'credential_id' => null, 'tenant_key' => 'org-a']] : [['credential_kind' => null, 'credential_id' => null, 'tenant_key' => null]], $rows);
                $this->assertNoSeed($rows);
                $warnings = implode("\n", array_map(static fn (array $w): string => $w['message'] . ' ' . json_encode($w['context'], JSON_THROW_ON_ERROR), $logger->warnings()));
                self::assertContains(TestRequestContextResolver::class, array_column(array_column($logger->warnings(), 'context'), 'resolver'));
                self::assertStringNotContainsString('SEED14', $warnings);
            } finally {
                $kernel->shutdown();
            }
        }
    }

    #[Test]
    public function shortRedactedValuesCannotBlankTheContextOfTheirOwnRequest(): void
    {
        [$kernel, $container] = $this->boot(self::CONFIG);

        try {
            // JA-M1: one-character values in a redacted query param, header and cookie
            $this->request($kernel, '/api/tasks/404?token=-&api_key=o', ['HTTP_AUTHORIZATION' => 'Bearer SEED14placeholder', 'HTTP_X_TEST_ORG' => 'org-a', 'HTTP_X_API_KEY' => '1', 'HTTP_COOKIE' => 'a=e'], 404);

            $rows = $this->em($container)->getConnection()->fetchAllAssociative('SELECT credential_kind, credential_id, tenant_key, project_key FROM nowo_http_log_entry');
            self::assertSame([['credential_kind' => 'personal_token', 'credential_id' => 'pt-17', 'tenant_key' => 'org-a', 'project_key' => 'project-1']], $rows);
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    public function aPercentEncodedPathIsMatchedDecodedLikeTheRouter(): void
    {
        // web records everything here: a missed channel would show up as a web row with the raw path
        [$kernel, $container] = $this->boot(['sampling_rate' => 1.0, 'ignore_path_prefixes' => []] + self::CONFIG);

        try {
            $headers = ['HTTP_X_TEST_ORG' => 'org-a'];
            $this->request($kernel, '/%61pi/share/SEED16placeholder', $headers, 403);
            $this->request($kernel, '/api%2Fshare/SEED16placeholder', $headers, 403);

            $rows = $this->em($container)->getConnection()->fetchAllAssociative('SELECT channel, path, route_name FROM nowo_http_log_entry ORDER BY id');
            self::assertSame([
                ['channel' => 'api', 'path' => '/api/share/{token}', 'route_name' => 'api_share'],
                ['channel' => 'api', 'path' => '/api/share/{token}', 'route_name' => 'api_share'],
            ], $rows);
            $this->assertNoSeed($rows);
            self::assertSame(2, (int) $this->em($container)->getConnection()->fetchOne("SELECT SUM(calls) FROM nowo_http_log_daily WHERE channel = 'api'"));
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    public function outboundCallsServingAnApiRequestInheritItsTenant(): void
    {
        [$kernel, $container] = $this->boot(['channels' => ['api' => ['path_prefixes' => ['/api']], 'mcp' => ['routes' => ['mcp_*']]]] + self::CONFIG);

        try {
            $this->request($kernel, '/api/sync', ['HTTP_X_TEST_ORG' => 'org-a']);
            // /mcp is behind the lazy main firewall: resolved at terminate only, the call does not inherit
            $this->request($kernel, '/mcp', ['HTTP_X_TEST_ORG' => 'org-b']);

            $rows = $this->em($container)->getConnection()->fetchAllAssociative('SELECT channel, tenant_key, project_key, path FROM nowo_http_log_entry ORDER BY id');
            self::assertSame([
                ['channel' => 'outbound', 'tenant_key' => 'org-a', 'project_key' => 'project-1', 'path' => '/api/v2/task/{id}'],
                ['channel' => 'api', 'tenant_key' => 'org-a', 'project_key' => 'project-1', 'path' => '/api/sync'],
                ['channel' => 'outbound', 'tenant_key' => null, 'project_key' => null, 'path' => '/api/v2/task/{id}'],
                ['channel' => 'mcp', 'tenant_key' => 'org-b', 'project_key' => 'project-1', 'path' => '/mcp'],
            ], $rows);

            $context = $container->get(HttpLogContext::class);
            self::assertInstanceOf(HttpLogContext::class, $context);
            self::assertSame(0, $context->depth(), 'the request frame is popped at terminate');
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    public function noTenantOrCredentialLeaksAcrossAThousandRequestsInOneKernel(): void
    {
        [$kernel, $container] = $this->boot(['channels' => ['api' => ['path_prefixes' => ['/api']]], 'rollup' => ['enabled' => false]] + self::CONFIG);

        try {
            $context = $container->get(HttpLogContext::class);
            self::assertInstanceOf(HttpLogContext::class, $context);
            $memory = 0;
            for ($i = 0; $i < 1000; ++$i) {
                $headers = $i % 2 === 0 ? ['HTTP_X_TEST_ORG' => 'org-' . $i, 'HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('x', 10 + $i % 7)] : [];
                $this->request($kernel, '/api/sync', $headers);
                self::assertSame(0, $context->depth());
                $container->get('services_resetter')->reset();
                if ($i === 100) {
                    $memory = memory_get_usage();
                }
            }

            $rows = $this->em($container)->getConnection()->fetchAllAssociative('SELECT channel, tenant_key, credential_id FROM nowo_http_log_entry WHERE id > 1900 ORDER BY id');
            foreach ($rows as $index => $row) {
                $request  = intdiv(1900 + $index, 2);
                $expected = $request % 2 === 0 ? 'org-' . $request : null;
                self::assertSame($expected, $row['tenant_key'], sprintf('row %d (%s)', 1901 + $index, $row['channel']));
                if ($expected === null) {
                    self::assertNull($row['credential_id']);
                }
            }
            self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $memory, 'memory flat');
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    public function withoutChannelsAndRollupNoneOfTheirServicesExist(): void
    {
        $kernel = new UsageKernel(['async' => false, 'outbound' => ['enabled' => true]], ['http_client' => ['mock_response_factory' => UsageMockResponses::class]], static function (ContainerBuilder $container): void {
            $container->register(UsageMockResponses::class, UsageMockResponses::class)->setPublic(true);
        }, 's4-off');
        $kernel->boot();

        try {
            $container = $this->testContainer($kernel);
            foreach ([InboundRequestRecorder::class, InboundRequestSubscriber::class, RouteTemplateMap::class, RollupSchemaListener::class] as $id) {
                self::assertFalse($container->has($id), $id);
            }
            self::assertFalse(file_exists($kernel->getCacheDir() . '/' . RouteTemplateMap::RELATIVE_PATH));
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{0: UsageKernel, 1: ContainerInterface, 2: CollectingLogger}
     */
    private function boot(array $config, string $mode = 'ok'): array
    {
        $kernel = new UsageKernel($config, ['http_client' => ['mock_response_factory' => UsageMockResponses::class]], static function (ContainerBuilder $container) use ($mode): void {
            $container->register(UsageMockResponses::class, UsageMockResponses::class)->setPublic(true);
            $container->register('logger', CollectingLogger::class)->setPublic(true);
            $container->register(TestRequestContextResolver::class, TestRequestContextResolver::class)
                ->setArguments([$mode])
                ->setPublic(true)
                ->setAutoconfigured(true);
            $container->register(ApiTestController::class, ApiTestController::class)
                ->setArguments([new Reference('http_client')])
                ->setPublic(true)
                ->addTag('controller.service_arguments');
        }, 's4-' . $mode);
        $kernel->boot();
        $container = $this->testContainer($kernel);
        $em        = $this->em($container);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
        $logger = $container->get('logger');
        self::assertInstanceOf(CollectingLogger::class, $logger);

        return [$kernel, $container, $logger];
    }

    /** @param array<string, string> $server */
    private function request(UsageKernel $kernel, string $uri, array $server = [], int $status = 200, string $method = 'GET', ?string $body = null): Response
    {
        $request  = Request::create($uri, $method, [], [], [], $server + ['REMOTE_ADDR' => '127.0.0.1'], $body);
        $response = $kernel->handle($request);
        self::assertSame($status, $response->getStatusCode(), $uri);
        $kernel->terminate($request, $response);

        return $response;
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertNoSeed(array $rows): void
    {
        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                foreach (self::SEEDS as $seed) {
                    foreach ([$seed, rawurlencode($seed), base64_encode($seed)] as $form) {
                        self::assertFalse(stripos((string) $value, $form) !== false, sprintf('%s leaks into %s', $seed, (string) $column));
                    }
                }
            }
        }
    }

    private function testContainer(UsageKernel $kernel): ContainerInterface
    {
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function em(ContainerInterface $container): EntityManagerInterface
    {
        $em = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
