<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection;

use Nowo\HttpLogBundle\Command\RollupBackfillCommand;
use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\Doctrine\UsageColumnsListener;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogSubscriber;
use Nowo\HttpLogBundle\HttpClient\OutboundCapturePolicy;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolution;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolverInterface;
use Nowo\HttpLogBundle\Inbound\InboundRequestRecorder;
use Nowo\HttpLogBundle\Inbound\InboundRequestSubscriber;
use Nowo\HttpLogBundle\Inbound\RouteTemplateCacheWarmer;
use Nowo\HttpLogBundle\Inbound\RouteTemplateMap;
use Nowo\HttpLogBundle\Mailer\MailerCaptureListener;
use Nowo\HttpLogBundle\Privacy\HttpLogUsageEraserInterface;
use Nowo\HttpLogBundle\Rollup\RollupSchemaListener;
use Nowo\HttpLogBundle\Rollup\UsageRollupStore;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use Nowo\HttpLogBundle\Usage\HttpLogUsageReaderInterface;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function str_repeat;

/**
 * HL-1 S4–S6 configuration: named channels and the rollup are off by default and register nothing; enabling them
 * registers their services; the configuration fails closed.
 */
final class ChannelsRollupExtensionTest extends TestCase
{
    /** Services of 1.16 that a host configuring nothing never gets. */
    private const SERVICES = [
        InboundRequestRecorder::class, InboundRequestSubscriber::class, RouteTemplateMap::class, RouteTemplateCacheWarmer::class,
        HttpLogRequestContextResolution::class, RollupSchemaListener::class, UsageRollupStore::class, RollupBackfillCommand::class,
    ];

    #[Test]
    public function nothingConfiguredRegistersNothing(): void
    {
        $container = $this->load([]);

        foreach ([...self::SERVICES, UsageColumnsListener::class] as $id) {
            self::assertFalse($this->registered($container, $id), $id);
        }
        self::assertFalse($container->hasAlias(HttpLogUsageReaderInterface::class));
        self::assertFalse($container->getParameter('nowo_http_log.rollup.enabled'));
        self::assertSame([], $container->getDefinition(HttpLogSubscriber::class)->getArguments()['$inbound'] ?? []);
        self::assertArrayHasKey(HttpLogRequestContextResolverInterface::class, $container->getAutoconfiguredInstanceof());
    }

    #[Test]
    public function aNamedChannelMapsTheUsageColumnsAndWiresTheInboundServices(): void
    {
        $container = $this->load(['channels' => ['api' => ['path_prefixes' => ['/api/'], 'retention_days' => 14]]]);

        foreach ([InboundRequestRecorder::class, InboundRequestSubscriber::class, RouteTemplateMap::class, RouteTemplateCacheWarmer::class, HttpLogRequestContextResolution::class, UsageColumnsListener::class] as $id) {
            self::assertTrue($container->hasDefinition($id), $id);
        }
        self::assertFalse($this->registered($container, UsageRollupStore::class));
        self::assertTrue($container->getParameter('nowo_http_log.usage.enabled'));
        self::assertSame(['api'], $container->getParameter('nowo_http_log.usage.channels'));
        self::assertSame(['api' => 14], $container->getParameter('nowo_http_log.usage.channel_retention'));
        self::assertEquals(new Reference(InboundRequestRecorder::class), $container->getDefinition(HttpLogSubscriber::class)->getArgument('$inbound'));
        self::assertTrue($container->getAlias(HttpLogUsageReaderInterface::class)->isPublic());
        self::assertTrue($container->getAlias(HttpLogUsageEraserInterface::class)->isPublic());
        self::assertTrue($container->getDefinition(RouteTemplateCacheWarmer::class)->hasTag('kernel.cache_warmer'));
        self::assertTrue($container->getDefinition(RouteTemplateMap::class)->hasTag('kernel.reset'));
        self::assertSame([false, false], [$container->getDefinition(InboundRequestRecorder::class)->getArgument(6), $container->getDefinition(InboundRequestRecorder::class)->getArgument(7)]);
    }

    #[Test]
    public function theRollupRegistersItsStoreSchemaListenerCommandAndPurgeScopes(): void
    {
        $container = $this->load([
            'sampling_rate' => 0.5,
            'channels'      => ['api' => ['path_prefixes' => ['/api'], 'error_status_min' => 500, 'sampling_rate' => 0.1], 'hooks' => ['path_prefixes' => ['/hooks'], 'rollup' => false]],
            'outbound'      => ['enabled' => true, 'providers' => ['uptime' => ['clients' => ['uptime.client'], 'per_call' => false], 'quiet' => ['hosts' => ['q.example'], 'rollup' => false]]],
            'mailer'        => ['enabled' => true],
            'rollup'        => ['enabled' => true, 'retention_months' => 6, 'dedupe_days' => 3, 'web' => true],
        ]);

        self::assertTrue($container->getDefinition(RollupSchemaListener::class)->hasTag('doctrine.event_listener'));
        self::assertSame([6, 3, ['api' => 500, 'hooks' => 400]], [$container->getDefinition(UsageRollupStore::class)->getArgument(3), $container->getDefinition(UsageRollupStore::class)->getArgument(4), $container->getDefinition(UsageRollupStore::class)->getArgument(5)]);
        self::assertSame(['api' => true, 'outbound' => false, 'mailer' => false, 'web' => true], $container->getDefinition(RollupBackfillCommand::class)->getArgument(2));
        self::assertSame(['quiet'], $container->getDefinition(RollupBackfillCommand::class)->getArgument(3));
        self::assertEquals(new Reference(UsageRollupStore::class), $container->getDefinition(PurgeHttpLogService::class)->getArgument('$rollup'));
        self::assertEquals(new Reference(UsageRollupStore::class), $container->getDefinition(UsageEntryWriter::class)->getArgument(1));
        self::assertTrue($container->getDefinition(MailerCaptureListener::class)->getArgument(5));
        $policy = $container->getDefinition(OutboundCapturePolicy::class);
        self::assertTrue($policy->getArgument(6));
        self::assertFalse($policy->getArgument(0)['quiet']['rollup']);
        self::assertSame([true, true], [$container->getDefinition(InboundRequestRecorder::class)->getArgument(6), $container->getDefinition(InboundRequestRecorder::class)->getArgument(7)]);
    }

    #[Test]
    public function rollupWebAloneWiresTheInboundRecorderWithoutUsageColumns(): void
    {
        $container = $this->load(['rollup' => ['enabled' => true, 'web' => true]]);

        self::assertTrue($container->hasDefinition(InboundRequestRecorder::class));
        self::assertFalse($this->registered($container, UsageColumnsListener::class));
        self::assertTrue($container->hasDefinition(UsageEntryWriter::class));
        self::assertSame(['web' => false], $container->getDefinition(RollupBackfillCommand::class)->getArgument(2));
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'reserved name' => [['channels' => ['web' => ['path_prefixes' => ['/w']]]]];
        yield 'rollup name' => [['channels' => ['rollup' => ['path_prefixes' => ['/w']]]]];
        yield 'bad name' => [['channels' => ['Api' => ['path_prefixes' => ['/api']]]]];
        yield 'name too long' => [['channels' => ['a_very_long_channel' => ['path_prefixes' => ['/api']]]]];
        yield 'nothing to match' => [['channels' => ['api' => []]]];
        yield 'relative prefix' => [['channels' => ['api' => ['path_prefixes' => ['api']]]]];
        yield 'prefix with a query' => [['channels' => ['api' => ['path_prefixes' => ['/api?x']]]]];
        yield 'shared prefix' => [['channels' => ['api' => ['path_prefixes' => ['/api']], 'v2' => ['path_prefixes' => ['/api/']]]]];
        yield 'sampling above 1' => [['channels' => ['api' => ['path_prefixes' => ['/api'], 'sampling_rate' => 1.5]]]];
        yield 'error status' => [['channels' => ['api' => ['path_prefixes' => ['/api'], 'error_status_min' => 99]]]];
        yield 'ip mode' => [['channels' => ['api' => ['path_prefixes' => ['/api'], 'client_ip' => 'hashed']]]];
        yield 'retention days' => [['channels' => ['api' => ['path_prefixes' => ['/api'], 'retention_days' => -1]]]];
        yield 'retention months' => [['rollup' => ['retention_months' => 0]]];
        yield 'dedupe days' => [['rollup' => ['dedupe_days' => 0]]];
    }

    /** @param array<string, mixed> $config */
    #[Test]
    #[DataProvider('invalid')]
    public function invalidConfigurationFailsClosed(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    #[Test]
    public function prefixesAreNormalisedAndRollupEnabledMustBeLiteral(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['channels' => ['api' => ['path_prefixes' => ['/api/', '/'], 'routes' => ['api_*']]], 'rollup' => ['retention_months' => null]]]);
        self::assertSame(['/api', '/'], $config['channels']['api']['path_prefixes']);
        self::assertNull($config['rollup']['retention_months']);

        $this->expectException(InvalidConfigurationException::class);
        $this->load(['rollup' => ['enabled' => '%env(bool:ROLLUP)%']]);
    }

    #[Test]
    public function longPrefixesAreRejectedWhileTheRollupIsMapped(): void
    {
        $this->load(['doctrine' => ['table_prefix' => 'p' . str_repeat('x', 22) . '_', 'table_name_prefix' => 'n' . str_repeat('x', 23) . '_']]);

        $this->expectException(InvalidConfigurationException::class);
        $this->load(['rollup' => ['enabled' => true], 'doctrine' => ['table_prefix' => 'p' . str_repeat('x', 22) . '_', 'table_name_prefix' => 'n' . str_repeat('x', 23) . '_']]);
    }

    private function registered(ContainerBuilder $container, string $id): bool
    {
        return $container->hasDefinition($id) && !$container->getDefinition($id)->hasTag('container.excluded');
    }

    /** @param array<string, mixed> $config */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        (new NowoHttpLogExtension())->load([['security' => ['allow_unauthenticated' => true]] + $config], $container);

        return $container;
    }
}
