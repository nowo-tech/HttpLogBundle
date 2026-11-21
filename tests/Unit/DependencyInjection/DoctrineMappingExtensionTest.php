<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection;

use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;

/**
 * 1.24 (DDD host, B3): `doctrine.register_mapping`, `mapping_type` and `entity_manager`. The default prepends the 1.23
 * mapping unchanged (standard Symfony path); the DDD path is opt-in.
 */
final class DoctrineMappingExtensionTest extends TestCase
{
    private const XML = [
        'type'      => 'xml',
        'dir'       => NowoHttpLogExtension::XML_MAPPING_DIR,
        'prefix'    => 'Nowo\\HttpLogBundle\\Entity',
        'is_bundle' => false,
    ];

    #[Test]
    public function theDefaultsKeepThe123Mapping(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertTrue($config['doctrine']['register_mapping']);
        self::assertSame('attribute', $config['doctrine']['mapping_type']);
        self::assertNull($config['doctrine']['entity_manager']);
        self::assertSame([['orm' => ['mappings' => ['NowoHttpLogBundle' => ['type' => 'attribute', 'is_bundle' => true]]]]], $this->prepended([]));
    }

    #[Test]
    public function theXmlMappingIsRegisteredOnTheDefaultManager(): void
    {
        self::assertSame(
            [['orm' => ['mappings' => ['NowoHttpLogBundle' => self::XML]]]],
            $this->prepended([['doctrine' => ['mapping_type' => 'attribute']], ['doctrine' => ['mapping_type' => 'xml']]]),
        );
        self::assertFileExists(NowoHttpLogExtension::XML_MAPPING_DIR . '/HttpLogEntry.orm.xml');
    }

    #[Test]
    public function aNamedManagerGetsTheMappingAlone(): void
    {
        $host = [['orm' => ['default_entity_manager' => 'default', 'entity_managers' => ['default' => ['auto_mapping' => true], 'http_log' => ['connection' => 'logs']]]]];

        self::assertSame(
            [['orm' => ['entity_managers' => ['http_log' => ['mappings' => ['NowoHttpLogBundle' => self::XML]]]]], ...$host],
            $this->prepended([['doctrine' => ['mapping_type' => 'xml', 'entity_manager' => 'http_log']]], $host),
        );
        self::assertSame(
            [['orm' => ['entity_managers' => ['http_log' => ['mappings' => ['NowoHttpLogBundle' => ['type' => 'attribute', 'is_bundle' => true]]]]]], ...$host],
            $this->prepended([['doctrine' => ['entity_manager' => 'http_log']]], $host),
        );
    }

    #[Test]
    public function theDefaultManagerOfAShortFormConfigurationCanBeNamed(): void
    {
        $host = [['dbal' => ['url' => 'sqlite:///:memory:'], 'orm' => ['auto_mapping' => true]]];

        self::assertSame(
            [['orm' => ['entity_managers' => ['default' => ['mappings' => ['NowoHttpLogBundle' => ['type' => 'attribute', 'is_bundle' => true]]]]]], ...$host],
            $this->prepended([['doctrine' => ['entity_manager' => 'default']]], $host),
        );

        $host = [['orm' => ['default_entity_manager' => 'main', 'mappings' => []]], ['orm' => ['entity_manager' => ['reports' => []]]]];
        self::assertCount(3, $this->prepended([['doctrine' => ['entity_manager' => 'main']]], $host));
        self::assertCount(3, $this->prepended([['doctrine' => ['entity_manager' => 'reports']]], $host));
    }

    #[Test]
    public function anUndeclaredManagerFailsInsteadOfCreatingOne(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_http_log.doctrine.entity_manager "http_log" is not a configured entity manager: declare it under doctrine.orm.entity_managers (configured: default).');

        $this->prepended([['doctrine' => ['entity_manager' => 'http_log']]], [['orm' => ['auto_mapping' => true]], ['dbal' => []]]);
    }

    #[Test]
    public function withoutRegisterMappingAutoMappingIsKeptOffTheEntity(): void
    {
        $host = [['orm' => ['default_entity_manager' => 'main', 'entity_managers' => ['main' => ['auto_mapping' => true], 'http_log' => []]]]];

        self::assertSame(
            [['orm' => ['entity_managers' => ['main' => ['mappings' => ['NowoHttpLogBundle' => ['mapping' => false]]]]]], ...$host],
            $this->prepended([['doctrine' => ['register_mapping' => false]]], $host),
        );
        self::assertSame(
            [['orm' => ['entity_managers' => ['http_log' => ['mappings' => ['NowoHttpLogBundle' => ['mapping' => false]]]]]], ...$host],
            $this->prepended([['doctrine' => ['register_mapping' => false, 'entity_manager' => 'http_log']]], $host),
        );
    }

    #[Test]
    public function withoutRegisterMappingAHostMappingUnderTheBundleNameIsLeftAlone(): void
    {
        foreach ([
            [['orm' => ['mappings' => ['NowoHttpLogBundle' => ['type' => 'xml']]]]],
            [['orm' => ['entity_managers' => ['logs' => ['mappings' => ['NowoHttpLogBundle' => ['type' => 'xml']]]]]]],
            [['orm' => ['entity_manager' => ['logs' => ['mappings' => ['NowoHttpLogBundle' => ['type' => 'xml']]]]]]],
        ] as $host) {
            self::assertSame($host, $this->prepended([['doctrine' => ['register_mapping' => false]]], $host));
        }

        // no ORM configured at all: nothing to keep auto_mapping off
        self::assertSame([['dbal' => []]], $this->prepended([['doctrine' => ['register_mapping' => false]]], [['dbal' => []]]));
    }

    #[Test]
    public function registerMappingMustBeALiteralBoolean(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_http_log.doctrine.register_mapping must be a literal boolean');

        $this->prepended([['doctrine' => ['register_mapping' => '%env(bool:HTTP_LOG_MAPPING)%']]]);
    }

    #[Test]
    public function anInvalidRawManagerNameFails(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid "nowo_http_log.doctrine.entity_manager" "%env(HTTP_LOG_EM)%"');

        $this->prepended([['doctrine' => ['entity_manager' => '%env(HTTP_LOG_EM)%']]]);
    }

    #[Test]
    public function aNullManagerMeansTheDefault(): void
    {
        $expected = [['orm' => ['mappings' => ['NowoHttpLogBundle' => ['type' => 'attribute', 'is_bundle' => true]]]]];

        self::assertSame($expected, $this->prepended([['doctrine' => ['entity_manager' => 'x']], ['doctrine' => ['entity_manager' => null]]]));
    }

    #[Test]
    #[DataProvider('invalidManagerNames')]
    public function configurationRejectsInvalidManagerNames(mixed $value): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['doctrine' => ['entity_manager' => $value]]]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidManagerNames(): iterable
    {
        yield 'dash' => ['http-log'];
        yield 'empty' => [''];
        yield 'digit first' => ['1logs'];
    }

    #[Test]
    public function configurationRejectsAnUnknownMappingType(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['doctrine' => ['mapping_type' => 'yaml']]]);
    }

    #[Test]
    public function loadPublishesTheOptions(): void
    {
        $container = $this->loaded(['doctrine' => ['register_mapping' => false, 'mapping_type' => 'xml', 'entity_manager' => 'http_log']]);

        self::assertFalse($container->getParameter('nowo_http_log.doctrine.register_mapping'));
        self::assertSame('xml', $container->getParameter('nowo_http_log.doctrine.mapping_type'));
        self::assertSame('http_log', $container->getParameter(NowoHttpLogExtension::ENTITY_MANAGER_PARAMETER));

        self::assertNull($this->loaded([])->getParameter(NowoHttpLogExtension::ENTITY_MANAGER_PARAMETER));
    }

    #[Test]
    public function loadRejectsAnEnvManagerName(): void
    {
        $container = new ContainerBuilder(new EnvPlaceholderParameterBag());
        $container->setParameter('kernel.environment', 'test');
        $value = $container->getParameterBag()->get('env(HTTP_LOG_EM)');
        self::assertIsString($value);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_http_log.doctrine.entity_manager does not support %env()%');

        (new NowoHttpLogExtension())->load([['security' => ['allow_unauthenticated' => true], 'doctrine' => ['entity_manager' => $value]]], $container);
    }

    /** @param array<string, mixed> $config */
    private function loaded(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        (new NowoHttpLogExtension())->load([['security' => ['allow_unauthenticated' => true]] + $config], $container);

        return $container;
    }

    /**
     * @param list<array<string, mixed>> $bundle raw nowo_http_log configurations
     * @param list<array<string, mixed>> $host raw doctrine configurations of the host
     *
     * @return list<array<string, mixed>> the doctrine configurations after prepend()
     */
    private function prepended(array $bundle, array $host = []): array
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->extension('doctrine'));
        $container->registerExtension(new NowoHttpLogExtension());
        foreach ($host as $config) {
            $container->loadFromExtension('doctrine', $config);
        }
        foreach ($bundle as $config) {
            $container->loadFromExtension('nowo_http_log', $config);
        }

        (new NowoHttpLogExtension())->prepend($container);

        return $container->getExtensionConfig('doctrine');
    }

    private function extension(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(private readonly string $aliasName)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return $this->aliasName;
            }
        };
    }
}
