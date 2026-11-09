<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\HttpClient;

use Nowo\HttpLogBundle\HttpClient\OutboundUrlTemplater;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * HL-1 §5.3: every templating rule, and the URL shapes of the redaction list (§4.3 cases 2–9).
 */
final class OutboundUrlTemplaterTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3?: int, 4?: list<string>}>
     */
    public static function urls(): iterable
    {
        yield 'words kept to depth 2' => ['https://api.example.com/v2/chat.postMessage/more', 'api.example.com', '/v2/chat.postMessage/{…}'];
        yield 'query, fragment and userinfo dropped (cases 3, 4)' => ['https://user:SEED9@Host.Example.com/x?token=SEED5&sig=SEED6#access_token=SEED8', 'host.example.com', '/x'];
        yield 'presigned S3 (case 2)' => ['https://bucket.s3.amazonaws.com/uploads/2026/file.pdf?X-Amz-Credential=TESTKEYIDSEED3%2F20261108&X-Amz-Signature=SEED4', 'bucket.s3.amazonaws.com', '/uploads/{id}/{…}'];
        yield 'slack webhook, depth 0 (case 5)' => ['https://hooks.slack.com/services/T0ABC/B0DEF/SEED10-placeholder', 'hooks.slack.com', '/{…}', 0];
        yield 'slack webhook, default depth' => ['https://hooks.slack.com/services/T0ABC/B0DEF/SEED10-placeholder', 'hooks.slack.com', '/services/{x}/{…}'];
        yield 'telegram bot token (case 6)' => ['https://api.telegram.org/bot123456:SEED11-abc/sendMessage', 'api.telegram.org', '/{x}/sendMessage'];
        yield 'discord webhook (case 7)' => ['https://discord.com/api/webhooks/1234567890/SEED12-placeholder', 'discord.com', '/api/webhooks/{…}'];
        yield 'discord webhook, depth 4' => ['https://discord.com/api/webhooks/1234567890/SEED12-placeholder', 'discord.com', '/api/webhooks/{id}/{x}', 4];
        yield 'e-mail and uuid segments (case 8)' => ['https://api.example.com/users/ana%40example.com/files/0f8fad5b-d9cb-469f-a165-70867728950e', 'api.example.com', '/users/{x}/files/{uuid}', 4];
        yield 'template wins' => ['https://api.clickup.com/api/v2/task/abc123', 'api.clickup.com', '/api/v2/task/{id}', 2, ['/api/v2/list/{id}/task', '/api/v2/task/{id}']];
        yield 'template literal mismatch falls back' => ['https://api.clickup.com/api/v3/task/abc123', 'api.clickup.com', '/api/v3/{…}', 2, ['/api/v2/task/{id}']];
        yield 'IDN host as punycode' => ['https://bücher.example/x', 'xn--bcher-kva.example', '/x'];
        yield 'IPv6 host and non-default port' => ['http://[::1]:8080/health', '[::1]:8080', '/health'];
        yield 'default port dropped' => ['https://api.example.com:443/v1', 'api.example.com', '/v1'];
        yield 'http default port dropped' => ['http://api.example.com:80/v1', 'api.example.com', '/v1'];
        yield 'custom port kept' => ['https://minio.internal:9000/bucket', 'minio.internal:9000', '/bucket'];
        yield 'empty path' => ['https://api.example.com', 'api.example.com', '/'];
        yield 'root path' => ['https://api.example.com/', 'api.example.com', '/'];
        yield '17 segments' => ['https://api.example.com' . str_repeat('/a', 17), 'api.example.com', str_repeat('/a', 16) . '/{…}', 20];
        yield '600-char path' => ['https://api.example.com/' . str_repeat('x', 600), 'api.example.com', '/{x}'];
        yield 'digits and hashes' => ['https://api.example.com/42/9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08', 'api.example.com', '/{id}/{x}'];
        yield 'word with trailing digits' => ['https://api.example.com/v12/abc123', 'api.example.com', '/v12/{x}'];
    }

    /**
     * @param list<string> $templates
     */
    #[Test]
    #[DataProvider('urls')]
    public function templates(string $url, string $host, string $path, int $depth = 2, array $templates = []): void
    {
        $parsed = OutboundUrlTemplater::parse($url);
        self::assertNotNull($parsed);
        self::assertSame($host, $parsed['host']);
        self::assertSame($path, OutboundUrlTemplater::path($parsed['segments'], $templates, $depth));
    }

    #[Test]
    public function unparsableUrlsAndHostsGiveNull(): void
    {
        self::assertNull(OutboundUrlTemplater::parse('not a url'));
        self::assertNull(OutboundUrlTemplater::parse('https:///path'));
        self::assertNull(OutboundUrlTemplater::parse('https://exa mple.com/'));
        self::assertNull(OutboundUrlTemplater::parse('https://ä' . str_repeat('a', 70) . '.example/'), 'IDN conversion failure');
    }

    #[Test]
    public function aVeryLongTemplatedPathCollapses(): void
    {
        $segments = [];
        for ($i = 0; $i < 16; ++$i) {
            $segments[] = 'abcdefghijklmnopqrstuvwxyzabcdef';
        }
        self::assertSame('/{…}', OutboundUrlTemplater::path($segments, [], 16));
    }

    #[Test]
    public function schemeLessUrlKeepsItsPort(): void
    {
        $parsed = OutboundUrlTemplater::parse('//api.example.com:443/v1');
        self::assertNotNull($parsed);
        self::assertNull($parsed['scheme']);
        self::assertSame('api.example.com:443', $parsed['host']);
    }
}
