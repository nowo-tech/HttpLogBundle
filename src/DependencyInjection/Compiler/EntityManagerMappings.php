<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function is_array;
use function is_string;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_starts_with;

/**
 * Reads DoctrineBundle's compiled definitions: which configured entity managers map the bundle entities, and the
 * connection of a manager. Shared by {@see HttpLogCommitTrackingPass} and {@see HttpLogEntityManagerPass}.
 *
 * @internal
 */
final class EntityManagerMappings
{
    /** Namespace of the bundle's entities. */
    public const ENTITY_NAMESPACE = 'Nowo\\HttpLogBundle\\Entity';

    /**
     * Every configured manager whose metadata driver chain has a driver for a namespace covering the bundle entities.
     *
     * @return list<string>
     */
    public static function managersMappingTheEntities(ContainerBuilder $container): array
    {
        $found = [];
        foreach (self::managerNames($container) as $name) {
            $chainId = sprintf('doctrine.orm.%s_metadata_driver', $name);
            if (!$container->hasDefinition($chainId)) {
                continue;
            }

            foreach ($container->getDefinition($chainId)->getMethodCalls() as $call) {
                if ($call[0] === 'addDriver' && self::mapsTheEntities($call[1])) {
                    $found[] = $name;

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Names of the configured entity managers (DoctrineBundle's `doctrine.entity_managers` parameter).
     *
     * @return list<string>
     */
    public static function managerNames(ContainerBuilder $container): array
    {
        $managers = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : [];
        $names    = [];
        foreach (array_keys(is_array($managers) ? $managers : []) as $name) {
            $names[] = (string) $name;
        }

        return $names;
    }

    public static function connectionOf(ContainerBuilder $container, string $manager): ?string
    {
        $id       = sprintf('doctrine.orm.%s_entity_manager', $manager);
        $argument = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        if ($argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /**
     * @param array<mixed> $arguments the `addDriver(driver, namespace)` arguments
     */
    private static function mapsTheEntities(array $arguments): bool
    {
        $namespace = $arguments[1] ?? null;
        if (!is_string($namespace)) {
            return false;
        }

        $mapped = rtrim($namespace, '\\') . '\\';
        $bundle = self::ENTITY_NAMESPACE . '\\';

        return str_starts_with($bundle, $mapped) || str_starts_with($mapped, $bundle);
    }
}
