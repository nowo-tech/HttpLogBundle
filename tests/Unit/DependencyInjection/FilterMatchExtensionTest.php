<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection;

use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\Doctrine\PathPrefixColumnListener;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** PERF-1147-02: `web_ui.filter_match` defaults to 1.16's contains match and maps path_prefix only when set to prefix. */
final class FilterMatchExtensionTest extends TestCase
{
    #[Test]
    public function containsIsTheDefaultAndMapsNoNewColumn(): void
    {
        $container = $this->load([]);

        self::assertSame(Configuration::FILTER_MATCH_CONTAINS, $container->getParameter('nowo_http_log.web_ui.filter_match'));
        self::assertFalse($this->registered($container, PathPrefixColumnListener::class));
    }

    #[Test]
    public function prefixRegistersThePathPrefixListenerBeforeTheTableNaming(): void
    {
        $container = $this->load(['web_ui' => ['filter_match' => 'prefix']]);

        self::assertSame(Configuration::FILTER_MATCH_PREFIX, $container->getParameter('nowo_http_log.web_ui.filter_match'));
        self::assertTrue($this->registered($container, PathPrefixColumnListener::class));
        self::assertSame(
            [['event' => 'loadClassMetadata', 'priority' => 100]],
            $container->getDefinition(PathPrefixColumnListener::class)->getTag('doctrine.event_listener'),
        );
    }

    #[Test]
    public function anUnknownMatchModeFailsClosed(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['web_ui' => ['filter_match' => 'regex']]);
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
