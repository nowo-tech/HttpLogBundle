<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\DependencyInjection\Compiler;

use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogConflictListener;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;
use function preg_match;
use function sprintf;

/**
 * 1.22 (REQ-RUNTIME-003 rule 6): scopes the commit tracking of the conflict answer (audit UE-CF-1: never every host
 * connection).
 *
 * - nowo-tech/transaction-doctrine's bundle registered with `commit_detection` (its {@see CommitLedger} service exists):
 *   its own middleware already counts every commit, so the bundle's middleware and ledger are removed and the conflict
 *   listener reads transaction-doctrine's ledger. No connection is wrapped twice.
 * - `conflict_response.commit_tracking: bundle` (default): the bundle's middleware wraps only the connection of the
 *   default entity manager (the one that holds the entries unless the host maps them elsewhere), never the host's
 *   other connections.
 * - `all`: every connection (the tag stays without a `connection` attribute).
 * - `off` (or no web UI): the extension registered nothing; this pass does nothing.
 *
 * Runs before DoctrineBundle's MiddlewaresPass (priority 0).
 */
final class HttpLogCommitTrackingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(NowoHttpLogExtension::COMMIT_TRACKING_MIDDLEWARE)) {
            return;
        }

        if ($container->has(CommitLedger::class)) {
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

        $container->getDefinition(NowoHttpLogExtension::COMMIT_TRACKING_MIDDLEWARE)
            ->clearTag('doctrine.middleware')
            ->addTag('doctrine.middleware', ['connection' => $this->bundleConnection($container)]);
    }

    /**
     * The connection of the default entity manager; the default connection when it cannot be resolved.
     */
    private function bundleConnection(ContainerBuilder $container): string
    {
        $manager = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : 'default';
        $id      = sprintf('doctrine.orm.%s_entity_manager', is_string($manager) && $manager !== '' ? $manager : 'default');

        $argument = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        if ($argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1) {
            return $match[1];
        }

        $default = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;

        return is_string($default) ? $default : 'default';
    }
}
