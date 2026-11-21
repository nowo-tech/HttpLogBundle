<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\DependencyInjection\Compiler;

use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogConflictListener;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_unique;
use function array_values;
use function is_string;

/**
 * 1.22 (REQ-RUNTIME-003 rule 6): scopes the commit tracking of the conflict answer (audit UE-CF-1: never every host
 * connection). Since 1.23 the default is `off` (audit UV-2): a purge that already deleted a batch answers CONFLICT_002
 * by itself, so no host connection is wrapped unless the host opts in.
 *
 * - nowo-tech/transaction-doctrine's bundle registered with `commit_detection`: its {@see CommitLedger} service exists
 *   AND its own tagged middleware (`nowo_transaction_doctrine.commit_tracking_middleware`) already counts every commit,
 *   so the bundle's middleware and ledger are removed and the conflict listener reads transaction-doctrine's ledger.
 *   No connection is wrapped twice. A `CommitLedger` service without that middleware (a host's own, or one registered
 *   by hand) is fed by nothing, so the bundle keeps its own tracking (audit UE3-CF-2).
 * - `conflict_response.commit_tracking: bundle`: the bundle's middleware wraps only the connections of the entity
 *   managers that may hold the entries: the default manager AND every manager whose mapping covers
 *   `Nowo\HttpLogBundle\Entity`, because `getManagerForClass()` may resolve any of them at runtime (audit UE3-CF-1).
 *   Never the host's other connections.
 * - `all`: every connection (the tag stays without a `connection` attribute).
 * - `off` (default, or no web UI): the extension registered nothing; this pass does nothing.
 *
 * Runs before DoctrineBundle's MiddlewaresPass (priority 0).
 */
final class HttpLogCommitTrackingPass implements CompilerPassInterface
{
    /** transaction-doctrine's own commit-tracking middleware, registered only with its `commit_detection` on. */
    public const TRANSACTION_DOCTRINE_MIDDLEWARE = 'nowo_transaction_doctrine.commit_tracking_middleware';

    /** Namespace of the bundle's entities. */
    public const ENTITY_NAMESPACE = EntityManagerMappings::ENTITY_NAMESPACE;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(NowoHttpLogExtension::COMMIT_TRACKING_MIDDLEWARE)) {
            return;
        }

        if ($this->transactionDoctrineTracksCommits($container)) {
            $container->removeDefinition(NowoHttpLogExtension::COMMIT_TRACKING_MIDDLEWARE);
            $container->removeDefinition(NowoHttpLogExtension::COMMIT_LEDGER_SERVICE);
            if ($container->hasDefinition(HttpLogConflictListener::class)) {
                $container->getDefinition(HttpLogConflictListener::class)->replaceArgument(1, new Reference(CommitLedger::class));
            }

            return;
        }

        $mode = $container->hasParameter(NowoHttpLogExtension::COMMIT_TRACKING_PARAMETER) ? $container->getParameter(NowoHttpLogExtension::COMMIT_TRACKING_PARAMETER) : 'bundle';
        if ($mode === 'all') {
            return;
        }

        $middleware = $container->getDefinition(NowoHttpLogExtension::COMMIT_TRACKING_MIDDLEWARE)->clearTag('doctrine.middleware');
        foreach ($this->bundleConnections($container) as $connection) {
            $middleware->addTag('doctrine.middleware', ['connection' => $connection]);
        }
    }

    /**
     * transaction-doctrine's ledger is fed only when its commit-tracking middleware is registered and tagged.
     */
    private function transactionDoctrineTracksCommits(ContainerBuilder $container): bool
    {
        return $container->has(CommitLedger::class)
            && $container->hasDefinition(self::TRANSACTION_DOCTRINE_MIDDLEWARE)
            && $container->getDefinition(self::TRANSACTION_DOCTRINE_MIDDLEWARE)->hasTag('doctrine.middleware');
    }

    /**
     * The connections of the default entity manager and of every manager mapping the entries; the default connection
     * when none can be resolved.
     *
     * @return list<string>
     */
    private function bundleConnections(ContainerBuilder $container): array
    {
        $default  = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : 'default';
        $managers = [is_string($default) && $default !== '' ? $default : 'default', ...EntityManagerMappings::managersMappingTheEntities($container)];

        $connections = [];
        foreach ($managers as $name) {
            $connection = EntityManagerMappings::connectionOf($container, $name);
            if ($connection !== null) {
                $connections[] = $connection;
            }
        }

        if ($connections === []) {
            $defaultConnection = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;
            $connections[]     = is_string($defaultConnection) ? $defaultConnection : 'default';
        }

        return array_values(array_unique($connections));
    }
}
