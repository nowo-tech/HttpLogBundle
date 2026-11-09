<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Usage;

use Nowo\HttpLogBundle\Context\HttpLogFrame;
use Nowo\HttpLogBundle\Tests\Support\UsageTestRig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function gc_collect_cycles;
use function memory_get_usage;

/**
 * HL-1 §7.3: 1 000 sequential requests / messages on the same service instances (FrankenPHP or Messenger worker):
 * no frame left behind, no tenant leaking from one to the next, an empty buffer after each boundary, flat memory.
 */
final class UsageWorkerModeTest extends TestCase
{
    #[Test]
    public function outboundCapturesDoNotLeakAcrossOneThousandRequests(): void
    {
        $rig    = new UsageTestRig();
        $client = $rig->client(new MockHttpClient(static fn (): MockResponse => new MockResponse('ok')));
        $memory = 0;

        for ($i = 1; $i <= 1000; ++$i) {
            // odd "requests" run for a tenant, even ones for nobody: a tenant must never leak into the next one
            $call = static fn (): string => $client->request('GET', 'https://api.example.com/v1/items')->getContent();
            $i % 2 === 1 ? $rig->context->runFrame(new HttpLogFrame('org-' . $i, null, 'msg-' . $i), $call) : $call();

            $rig->buffer->flush(); // kernel.terminate / WorkerMessageHandledEvent
            $rig->messages = [];   // the transport took them

            self::assertSame(0, $rig->context->depth());
            self::assertSame(0, $rig->buffer->count());
            if ($i === 100) {
                gc_collect_cycles();
                $memory = memory_get_usage();
            }
        }

        gc_collect_cycles();
        self::assertLessThan(512 * 1024, memory_get_usage() - $memory, 'memory stays flat');
    }

    #[Test]
    public function tenantsOfConsecutiveRequestsStayApart(): void
    {
        $rig    = new UsageTestRig();
        $client = $rig->client(new MockHttpClient(static fn (): MockResponse => new MockResponse('ok')));

        for ($i = 1; $i <= 1000; ++$i) {
            $call = static fn (): string => $client->request('GET', 'https://api.example.com/v1/items')->getContent();
            $i % 2 === 1 ? $rig->context->run('org-' . $i, $call) : $call();
            if ($i % 100 === 0) {
                $rig->persist();
            }
            $rig->context->reset(); // kernel.reset between requests
            $rig->buffer->reset();
        }
        $rig->persist();

        $rows = $rig->rows();
        self::assertCount(1000, $rows);
        foreach ($rows as $n => $row) {
            self::assertSame(($n + 1) % 2 === 1 ? 'org-' . ($n + 1) : null, $row['tenant_key']);
        }
    }
}
