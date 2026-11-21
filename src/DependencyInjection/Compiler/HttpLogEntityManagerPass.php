<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\DependencyInjection\Compiler;

use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

use function implode;
use function in_array;
use function is_string;
use function sprintf;

/**
 * 1.24 (DDD host, B3): with `doctrine.entity_manager` set, the bundle's services resolve the manager of the entries
 * through `ManagerRegistry::getManagerForClass(HttpLogEntry::class)`, which answers the FIRST manager that maps the
 * class. The build therefore fails unless the named manager exists and is the only manager mapping the bundle entities
 * (otherwise rows could be written through a host manager, or a host manager's connection, the host never chose).
 *
 * Nothing is checked without `doctrine.entity_manager` (1.23 behaviour) or without DoctrineBundle's ORM.
 */
final class HttpLogEntityManagerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(NowoHttpLogExtension::ENTITY_MANAGER_PARAMETER)) {
            return;
        }

        $manager = $container->getParameter(NowoHttpLogExtension::ENTITY_MANAGER_PARAMETER);
        if (!is_string($manager) || !$container->hasParameter('doctrine.entity_managers')) {
            return;
        }

        $names = EntityManagerMappings::managerNames($container);
        if (!in_array($manager, $names, true)) {
            throw new InvalidArgumentException(sprintf('nowo_http_log.doctrine.entity_manager "%s" is not a configured entity manager (configured: %s).', $manager, implode(', ', $names)));
        }

        $mapping = EntityManagerMappings::managersMappingTheEntities($container);
        if ($mapping !== [$manager]) {
            throw new InvalidArgumentException(sprintf('nowo_http_log.doctrine.entity_manager is "%s", but the bundle entities (%s) are mapped on: %s. Map them on "%s" only: keep doctrine.register_mapping true, or with register_mapping false map them yourself on that manager and nowhere else (Doctrine auto_mapping included).', $manager, EntityManagerMappings::ENTITY_NAMESPACE, $mapping === [] ? 'no entity manager' : implode(', ', $mapping), $manager));
        }
    }
}
