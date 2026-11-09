<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\HttpClient;

use Nowo\HttpLogBundle\Enum\OutboundErrorKind;
use Nowo\HttpLogBundle\HttpClient\OutboundCapturePolicy;
use Nowo\HttpLogBundle\HttpClient\OutboundErrorClassifier;
use Nowo\HttpLogBundle\HttpClient\OutboundTarget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;

final class OutboundCapturePolicyTest extends TestCase
{
    private const PROVIDERS = [
        'clickup' => ['clients' => ['clickup.client'], 'hosts' => ['api.clickup.com'], 'path_templates' => ['/api/v2/task/{id}'], 'path_depth' => null, 'per_call' => true],
        's3'      => ['clients' => [], 'hosts' => ['*.amazonaws.com'], 'path_templates' => [], 'path_depth' => 1, 'per_call' => true],
        's3exact' => ['clients' => [], 'hosts' => ['special.s3.amazonaws.com'], 'path_templates' => [], 'path_depth' => 0, 'per_call' => true],
        'uptime'  => ['clients' => ['uptime.http_client'], 'hosts' => [], 'path_templates' => [], 'path_depth' => 0, 'per_call' => false],
    ];

    #[Test]
    public function providerPrecedence(): void
    {
        $policy = new OutboundCapturePolicy(self::PROVIDERS);

        // client id beats hosts
        self::assertSame('uptime', $policy->target('https://api.clickup.com/x', 'uptime.http_client')?->provider);
        self::assertSame('clickup', $policy->target('https://anything.example/x', 'clickup.client')?->provider);
        // exact host beats a wildcard listed before it
        self::assertSame('s3exact', $policy->target('https://special.s3.amazonaws.com/bucket/key')?->provider);
        self::assertSame('s3', $policy->target('https://bucket.s3.eu-west-1.amazonaws.com/bucket/key')?->provider);
        self::assertSame('other', $policy->target('https://amazonaws.com/x')?->provider, 'the wildcard needs a label');
        self::assertSame('other', $policy->target('https://unknown.example/x')?->provider);
        // the per-request provider wins when it names a configured provider
        self::assertSame('s3', $policy->target('https://api.clickup.com/x', 'clickup.client', null, 's3')?->provider);
        self::assertSame('clickup', $policy->target('https://api.clickup.com/x', null, null, 'not-configured')?->provider);
        // the provider of a wrapped client beats client id and hosts
        self::assertSame('webpush', $policy->target('https://api.clickup.com/x', 'clickup.client', 'webpush')?->provider);
    }

    #[Test]
    public function targetAppliesTheProviderPathRules(): void
    {
        $policy = new OutboundCapturePolicy(self::PROVIDERS);

        self::assertEquals(new OutboundTarget('clickup', 'https', 'api.clickup.com', '/api/v2/task/{id}'), $policy->target('https://API.clickup.com/api/v2/task/86abc?include=secret'));
        self::assertSame('/bucket/{…}', $policy->target('https://b.s3.amazonaws.com/bucket/2026/key')?->path);
        self::assertSame('/{…}', $policy->target('https://x.example/a/b/c', 'uptime.http_client')?->path);
        self::assertFalse($policy->target('https://x.example/', 'uptime.http_client')?->perCall);
        self::assertEquals(new OutboundTarget('other', null, 'invalid', '/{…}'), $policy->target('::not a url::'));
    }

    #[Test]
    public function unmappedHostsCanBeDropped(): void
    {
        $policy = new OutboundCapturePolicy(self::PROVIDERS, recordUnmapped: false);

        self::assertNull($policy->target('https://unknown.example/x'));
        self::assertNotNull($policy->target('https://api.clickup.com/x'));
    }

    #[Test]
    public function errorsAreAlwaysKeptSuccessesSampledPerCallFalseNever(): void
    {
        $target = new OutboundTarget('p', 'https', 'h', '/');
        $uptime = new OutboundTarget('uptime', 'https', 'h', '/', false);

        self::assertTrue((new OutboundCapturePolicy([], 0.0))->keep($target, true));
        self::assertFalse((new OutboundCapturePolicy([], 0.0))->keep($target, false));
        self::assertTrue((new OutboundCapturePolicy([], 1.0))->keep($target, false));
        self::assertFalse((new OutboundCapturePolicy([], 1.0))->keep($uptime, true));

        $kept = 0;
        for ($i = 0; $i < 200; ++$i) {
            $kept += (new OutboundCapturePolicy([], 0.5))->keep($target, false) ? 1 : 0;
        }
        self::assertGreaterThan(0, $kept);
        self::assertLessThan(200, $kept);
    }

    #[Test]
    public function errorKindsAreClassifiedWithoutKeepingTheMessage(): void
    {
        $url = 'https://bucket.s3.amazonaws.com/k?X-Amz-Signature=SEED4';

        self::assertSame(OutboundErrorKind::Timeout, OutboundErrorClassifier::classify('Idle timeout reached for "' . $url . '".'));
        self::assertSame(OutboundErrorKind::Timeout, OutboundErrorClassifier::classify(null, new TimeoutException('x')));
        self::assertSame(OutboundErrorKind::Dns, OutboundErrorClassifier::classify('Could not resolve host: bucket.s3.amazonaws.com'));
        self::assertSame(OutboundErrorKind::Tls, OutboundErrorClassifier::classify('SSL certificate problem: unable to get local issuer certificate'));
        self::assertSame(OutboundErrorKind::Connect, OutboundErrorKind::from(OutboundErrorClassifier::classify('Failed to connect to 10.0.0.1 port 443: Connection refused')->value));
        self::assertSame(OutboundErrorKind::RedirectLimit, OutboundErrorClassifier::classify('Maximum number of redirects (20) reached.'));
        self::assertSame(OutboundErrorKind::Cancelled, OutboundErrorClassifier::classify('Response has been canceled.'));
        self::assertSame(OutboundErrorKind::Transport, OutboundErrorClassifier::classify('Something odd', new TransportException('x')));
        self::assertSame(OutboundErrorKind::Transport, OutboundErrorClassifier::classify(null));
        self::assertSame(OutboundErrorKind::Exception, OutboundErrorClassifier::classify('Idle timeout', new RuntimeException('x')));
    }
}
