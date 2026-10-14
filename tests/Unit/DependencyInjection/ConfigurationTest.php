<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection;

use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\Enum\CssFramework;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertTrue($config['enabled']);
        self::assertSame([], $config['environments']);
        self::assertTrue($config['async']);
        self::assertSame(1.0, $config['sampling_rate']);
        self::assertFalse($config['track_sub_requests']);
        self::assertContains('_wdt', $config['ignore_routes']);
        self::assertContains('/admin/http-log', $config['ignore_path_prefixes']);
        self::assertFalse($config['capture']['request_body']);
        self::assertTrue($config['capture']['response_body_by_type']['json']);
        self::assertFalse($config['capture']['response_body_by_type']['html']);
        self::assertSame(65536, $config['capture']['max_body_bytes']);
        self::assertContains('Authorization', $config['redaction']['headers']);
        self::assertSame(['/admin/http-log'], $config['ignore_path_prefixes']);
        self::assertTrue($config['ignore_default_path_prefixes']);
        self::assertSame(30, $config['retention']['days']);
        self::assertSame(1000, $config['retention']['purge_batch_size']);
        self::assertSame(['csv', 'json'], $config['export']['formats']);
        self::assertTrue($config['web_ui']['enabled']);
        self::assertSame('/admin/http-log', $config['web_ui']['path_prefix']);
        self::assertSame(CssFramework::Bootstrap5->value, $config['web_ui']['css_framework']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertFalse($config['security']['allow_unauthenticated']);
    }

    public function testProcessesCustomConfiguration(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'enabled'       => false,
            'sampling_rate' => 0.5,
            'retention'     => ['days' => null],
            'web_ui'        => [
                'path_prefix'   => '/logs',
                'css_framework' => CssFramework::Tailwind->value,
            ],
        ]]);

        self::assertFalse($config['enabled']);
        self::assertSame(0.5, $config['sampling_rate']);
        self::assertNull($config['retention']['days']);
        self::assertSame('/logs', $config['web_ui']['path_prefix']);
        self::assertSame(CssFramework::Tailwind->value, $config['web_ui']['css_framework']);
    }

    public function testRejectsNonPositivePurgeBatchSize(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'retention' => ['purge_batch_size' => 0],
        ]]);
    }

    public function testRejectsInvalidPathPrefix(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['path_prefix' => 'admin/http-log'],
        ]]);
    }

    public function testPathPrefixTrailingSlashIsTrimmed(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['path_prefix' => '/ops/http-log/'],
        ]]);

        self::assertSame('/ops/http-log', $config['web_ui']['path_prefix']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPathPrefixes(): iterable
    {
        yield 'root only' => ['/'];
        yield 'slashes only' => ['//'];
        yield 'parameter placeholder' => ['/%kernel.secret%'];
    }

    #[DataProvider('invalidPathPrefixes')]
    public function testRejectsUnusablePathPrefixes(string $prefix): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'web_ui' => ['path_prefix' => $prefix],
        ]]);
    }

    public function testExportFormatsAreRestrictedToSupportedFormats(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'export' => ['formats' => ['json']],
        ]]);
        self::assertSame(['json'], $config['export']['formats']);

        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [[
            'export' => ['formats' => ['xml']],
        ]]);
    }

    public function testExportFormatsCannotBeEmpty(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'export' => ['formats' => []],
        ]]);
    }

    public function testIconSetDefaultsToBootstrapIcons(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertSame('bootstrap-icons', $config['web_ui']['icon_set']);
    }

    public function testRejectsNegativeRetentionDays(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'retention' => ['days' => -1],
        ]]);
    }

    public function testSamplingRateBounds(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [[
            'sampling_rate' => 1.5,
        ]]);
    }
}
