<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Tests\Kernel\PublicServicesPass;
use Nowo\HttpLogBundle\Tests\Kernel\UsageKernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function gc_collect_cycles;
use function memory_get_usage;

/**
 * FrankenPHP worker mode with the kernel kept alive (FRANKENPHP_RESET_KERNEL=false, no services resetter): one booted
 * kernel serves 1,200 requests with `async: false`. Memory stays flat, the shared EntityManager never holds an entry,
 * and every row carries its own request's client and user (no state leaks between two visitors).
 */
final class WorkerModeKeptAliveKernelTest extends TestCase
{
    private const REQUESTS = 1200;

    private const WARMUP = 200;

    #[Test]
    public function aKeptAliveKernelRecordsManyRequestsWithFlatMemoryAndNoLeak(): void
    {
        $kernel = new UsageKernel(['async' => false, 'capture' => ['user' => true]], [], static function (ContainerBuilder $container): void {
            $container->addCompilerPass(new PublicServicesPass([HttpLogRecorder::class, 'security.token_storage']), PassConfig::TYPE_BEFORE_REMOVING);
        }, 'worker-kept-alive');
        $kernel->boot();

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $em = $container->get('doctrine.orm.entity_manager');
            self::assertInstanceOf(EntityManagerInterface::class, $em);
            (new SchemaTool($em))->createSchema([$em->getClassMetadata(HttpLogEntry::class)]);

            // 1) full HTTP cycle (handle + terminate) for two alternating visitors
            $baseline = 0;
            for ($i = 0; $i < self::REQUESTS; ++$i) {
                if ($i === self::WARMUP) {
                    gc_collect_cycles();
                    $baseline = memory_get_usage();
                }
                $request  = Request::create('/hello', 'GET', server: ['REMOTE_ADDR' => $i % 2 === 0 ? '10.0.0.1' : '10.0.0.2']);
                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);
                self::assertSame(0, $em->getUnitOfWork()->size());
            }
            gc_collect_cycles();
            self::assertLessThan(1024 * 1024, memory_get_usage() - $baseline, 'memory must stay flat across requests');

            $rows = $em->getConnection()->fetchAllAssociative('SELECT client_ip, COUNT(*) AS n FROM nowo_http_log_entry GROUP BY client_ip ORDER BY client_ip');
            self::assertSame([['client_ip' => '10.0.0.1', 'n' => 600], ['client_ip' => '10.0.0.2', 'n' => 600]], array_map(static fn (array $r): array => ['client_ip' => $r['client_ip'], 'n' => (int) $r['n']], $rows));

            // 2) the same recorder instance, two users alternating on the shared token storage
            $recorder = $container->get(HttpLogRecorder::class);
            self::assertInstanceOf(HttpLogRecorder::class, $recorder);
            $tokens = $container->get('security.token_storage');
            self::assertInstanceOf(TokenStorageInterface::class, $tokens);
            $em->getConnection()->executeStatement('DELETE FROM nowo_http_log_entry');
            for ($i = 0; $i < self::REQUESTS; ++$i) {
                $user = $i % 2 === 0 ? 'alice' : 'bob';
                $tokens->setToken(new UsernamePasswordToken(new InMemoryUser($user, null), 'main'));
                $recorder->record(Request::create('/hello?u=' . $user), new Response('ok'), 1.0, 'req-' . $i);
            }
            $tokens->setToken(null);

            $mismatched = (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM nowo_http_log_entry WHERE user_identifier IS NULL OR query_params NOT LIKE '%' || user_identifier || '%'");
            self::assertSame(0, $mismatched, 'each row carries the user of its own request');
            self::assertSame(self::REQUESTS, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_http_log_entry'));
            self::assertSame(0, $em->getUnitOfWork()->size());
        } finally {
            $kernel->shutdown();
        }
    }
}
