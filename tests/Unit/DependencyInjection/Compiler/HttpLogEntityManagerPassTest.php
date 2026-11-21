<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\HttpLogBundle\DependencyInjection\Compiler\EntityManagerMappings;
use Nowo\HttpLogBundle\DependencyInjection\Compiler\HttpLogEntityManagerPass;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * 1.24 (DDD host, B3): `doctrine.entity_manager` must name a configured manager that is the only one mapping the
 * entries, since the services resolve it with `getManagerForClass()`.
 */
#[CoversClass(HttpLogEntityManagerPass::class)]
#[CoversClass(EntityManagerMappings::class)]
final class HttpLogEntityManagerPassTest extends TestCase
{
    public function testTheNamedManagerMappingTheEntitiesAloneIsAccepted(): void
    {
        $container = $this->container('http_log', ['http_log' => 'Nowo\\HttpLogBundle\\Entity', 'default' => 'App\\Entity']);

        (new HttpLogEntityManagerPass())->process($container);

        self::assertSame(['http_log'], EntityManagerMappings::managersMappingTheEntities($container));
    }

    public function testNothingIsCheckedWithoutTheOptionOrWithoutDoctrine(): void
    {
        $this->expectNotToPerformAssertions();

        (new HttpLogEntityManagerPass())->process(new ContainerBuilder());
        // 1.23 behaviour: no manager named, the entries may be mapped anywhere
        (new HttpLogEntityManagerPass())->process($this->container(null, ['default' => 'Nowo\\HttpLogBundle\\Entity', 'http_log' => 'Nowo\\HttpLogBundle\\Entity']));

        $container = new ContainerBuilder();
        $container->setParameter(NowoHttpLogExtension::ENTITY_MANAGER_PARAMETER, 'http_log');
        (new HttpLogEntityManagerPass())->process($container);
    }

    public function testAnUnknownManagerFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nowo_http_log.doctrine.entity_manager "logs" is not a configured entity manager (configured: default, http_log).');

        (new HttpLogEntityManagerPass())->process($this->container('logs', ['http_log' => 'Nowo\\HttpLogBundle\\Entity']));
    }

    public function testASecondManagerMappingTheEntitiesFails(): void
    {
        // e.g. Doctrine auto_mapping on the default manager next to a host mapping on the dedicated one
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nowo_http_log.doctrine.entity_manager is "http_log", but the bundle entities (Nowo\\HttpLogBundle\\Entity) are mapped on: default, http_log.');

        (new HttpLogEntityManagerPass())->process($this->container('http_log', ['default' => 'Nowo\\HttpLogBundle\\Entity', 'http_log' => 'Nowo\\HttpLogBundle\\Entity']));
    }

    public function testTheNamedManagerNotMappingTheEntitiesFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('are mapped on: no entity manager.');

        (new HttpLogEntityManagerPass())->process($this->container('http_log', ['default' => 'App\\Entity']));
    }

    /**
     * @param array<string, string> $mappings manager => mapped namespace
     */
    private function container(?string $manager, array $mappings): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoHttpLogExtension::ENTITY_MANAGER_PARAMETER, $manager);
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager', 'http_log' => 'doctrine.orm.http_log_entity_manager']);
        foreach ($mappings as $name => $namespace) {
            $container->setDefinition('doctrine.orm.' . $name . '_metadata_driver', (new Definition())->addMethodCall('addDriver', [new Definition(), $namespace]));
        }

        return $container;
    }
}
