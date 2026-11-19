<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\MiddlewaresPass;
use Nowo\HttpLogBundle\DependencyInjection\Compiler\HttpLogCommitTrackingPass;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogConflictListener;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Audit UE-CF-1: the bundle's commit-tracking middleware (opt-in since 1.23) never wraps the host's other connections,
 * and is not registered at all when transaction-doctrine's commit_detection already counts commits (UE3-CF-1/2).
 */
#[CoversClass(HttpLogCommitTrackingPass::class)]
final class HttpLogCommitTrackingPassTest extends TestCase
{
    private const MIDDLEWARE = NowoHttpLogExtension::COMMIT_TRACKING_MIDDLEWARE;

    public function testBundleModeWrapsOnlyTheConnectionOfTheDefaultEntityManager(): void
    {
        $container = $this->container('bundle');
        $container->setParameter('doctrine.default_entity_manager', 'logs');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.logs'), 'the connection of the manager holding the entries');
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'), 'never the host\'s other connections');
        self::assertSame(NowoHttpLogExtension::COMMIT_LEDGER_SERVICE, (string) $container->getDefinition(HttpLogConflictListener::class)->getArgument(1));
    }

    public function testAnUnresolvableManagerFallsBackToTheDefaultConnection(): void
    {
        $container = $this->container('bundle');
        $container->setParameter('doctrine.default_entity_manager', 'missing');
        $container->setParameter('doctrine.default_connection', 'logs');
        $this->compile($container);
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.logs'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));

        $container = $this->container('bundle');
        $container->getParameterBag()->remove(NowoHttpLogExtension::COMMIT_TRACKING_PARAMETER);
        $container->setParameter('doctrine.default_entity_manager', '');
        $this->compile($container);
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'), 'no parameter: bundle mode; no default manager or connection: "default"');
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.logs'));

        $container = $this->container('bundle');
        $container->setParameter('doctrine.default_entity_manager', 'missing');
        $container->setParameter('doctrine.default_connection', null);
        $this->compile($container);
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'));
    }

    public function testAllWrapsEveryConnection(): void
    {
        $container = $this->container('all');

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.logs'));
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'));
    }

    public function testTransactionDoctrineCommitDetectionIsReusedAndNoConnectionIsWrappedTwice(): void
    {
        $container = $this->container('all');
        $container->setDefinition('nowo_transaction_doctrine.commit_ledger', new Definition(CommitLedger::class));
        $container->setAlias(CommitLedger::class, 'nowo_transaction_doctrine.commit_ledger');
        $this->registerTransactionDoctrineMiddleware($container);

        $this->compile($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
        self::assertFalse($container->hasDefinition(NowoHttpLogExtension::COMMIT_LEDGER_SERVICE));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.logs'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));
        self::assertSame(CommitLedger::class, (string) $container->getDefinition(HttpLogConflictListener::class)->getArgument(1));
    }

    public function testTransactionDoctrineLedgerWithoutTheListenerOnlyDropsTheMiddleware(): void
    {
        $container = $this->container('bundle');
        $container->removeDefinition(HttpLogConflictListener::class);
        $container->setDefinition(CommitLedger::class, new Definition(CommitLedger::class));
        $this->registerTransactionDoctrineMiddleware($container);

        (new HttpLogCommitTrackingPass())->process($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
    }

    /**
     * Audit UE3-CF-2: a CommitLedger service that transaction-doctrine's tagged middleware does not feed would silence
     * CONFLICT_002, so the bundle keeps its own tracking.
     */
    public function testALedgerWithoutTransactionDoctrinesTaggedMiddlewareKeepsTheBundlesTracking(): void
    {
        foreach ([false, true] as $registered) {
            $container = $this->container('bundle');
            $container->setDefinition(CommitLedger::class, new Definition(CommitLedger::class));
            if ($registered) {
                // registered but untagged: it wraps no connection
                $container->setDefinition(HttpLogCommitTrackingPass::TRANSACTION_DOCTRINE_MIDDLEWARE, new Definition(CommitTrackingMiddleware::class));
            }

            $this->compile($container);

            self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'));
            self::assertSame(NowoHttpLogExtension::COMMIT_LEDGER_SERVICE, (string) $container->getDefinition(HttpLogConflictListener::class)->getArgument(1));
        }
    }

    /**
     * Audit UE3-CF-1: the entries mapped on a manager that is not the default one: that manager's connection is wrapped
     * too, never a connection of a manager that does not map them.
     */
    public function testTheConnectionsOfEveryManagerMappingTheEntriesAreWrapped(): void
    {
        $container = $this->container('bundle');
        $container->setParameter('doctrine.default_entity_manager', 'default');
        $container->setParameter('doctrine.entity_managers', ['default' => 'doctrine.orm.default_entity_manager', 'logs' => 'doctrine.orm.logs_entity_manager', 'reports' => 'doctrine.orm.reports_entity_manager', 'other' => 'doctrine.orm.other_entity_manager']);
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection', 'logs' => 'doctrine.dbal.logs_connection', 'reports' => 'doctrine.dbal.reports_connection']);
        $container->setDefinition('doctrine.dbal.reports_connection.configuration', new Definition());
        $container->setDefinition('doctrine.orm.default_entity_manager', new Definition(null, [new Reference('doctrine.dbal.default_connection')]));
        $container->setDefinition('doctrine.orm.reports_entity_manager', new Definition(null, [new Reference('doctrine.dbal.reports_connection')]));
        $container->setDefinition('doctrine.orm.logs_metadata_driver', (new Definition())
            ->addMethodCall('setDefaultDriver', [new Definition()])
            ->addMethodCall('addDriver', [new Definition(), 'App\\Entity'])
            ->addMethodCall('addDriver', [new Definition(), 'Nowo\\HttpLogBundle\\Entity']));
        $container->setDefinition('doctrine.orm.reports_metadata_driver', (new Definition())
            ->addMethodCall('addDriver', [new Definition(), null])
            ->addMethodCall('addDriver', [new Definition(), 'Nowo\\HttpLogBundle\\Entity\\Report']));
        $container->setDefinition('doctrine.orm.other_metadata_driver', (new Definition())
            ->addMethodCall('addDriver', [new Definition(), 'Nowo\\HttpLogBundleExtra\\Entity']));

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'), 'the default manager');
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.logs'), 'a manager mapping the entries');
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.reports'), 'a mapping inside the entity namespace');
    }

    public function testAManagerMappingAParentNamespaceAndOneWithoutAChainAreHandled(): void
    {
        $container = $this->container('bundle');
        $container->setParameter('doctrine.default_entity_manager', 'missing');
        $container->setParameter('doctrine.entity_managers', ['logs' => 'doctrine.orm.logs_entity_manager', 'nochain' => 'doctrine.orm.nochain_entity_manager']);
        $container->setDefinition('doctrine.orm.logs_metadata_driver', (new Definition())->addMethodCall('addDriver', [new Definition(), 'Nowo\\HttpLogBundle\\']));

        $this->compile($container);

        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.logs'));
        self::assertFalse($container->hasDefinition(self::MIDDLEWARE . '.default'));

        $container = $this->container('bundle');
        $container->setParameter('doctrine.entity_managers', 'not a map');
        $container->setParameter('doctrine.default_entity_manager', 'missing');
        $this->compile($container);
        self::assertTrue($container->hasDefinition(self::MIDDLEWARE . '.default'), 'nothing resolved: the default connection');
    }

    public function testOffRegistersNothingAndThePassLeavesTheContainerAlone(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoHttpLogExtension::COMMIT_TRACKING_PARAMETER, 'off');

        (new HttpLogCommitTrackingPass())->process($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
    }

    private function container(string $mode): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoHttpLogExtension::COMMIT_TRACKING_PARAMETER, $mode);
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection', 'logs' => 'doctrine.dbal.logs_connection']);
        $container->setDefinition('doctrine.dbal.default_connection.configuration', new Definition());
        $container->setDefinition('doctrine.dbal.logs_connection.configuration', new Definition());
        $container->setDefinition('doctrine.orm.logs_entity_manager', new Definition(null, [new Reference('doctrine.dbal.logs_connection')]));
        $container->setDefinition(NowoHttpLogExtension::COMMIT_LEDGER_SERVICE, new Definition(CommitLedger::class));
        $container->setDefinition(self::MIDDLEWARE, (new Definition(CommitTrackingMiddleware::class, [new Reference(NowoHttpLogExtension::COMMIT_LEDGER_SERVICE)]))
            ->addTag('doctrine.middleware'));
        $container->setDefinition(HttpLogConflictListener::class, new Definition(HttpLogConflictListener::class, [null, new Reference(NowoHttpLogExtension::COMMIT_LEDGER_SERVICE), null]));

        return $container;
    }

    private function registerTransactionDoctrineMiddleware(ContainerBuilder $container): void
    {
        $container->setDefinition(HttpLogCommitTrackingPass::TRANSACTION_DOCTRINE_MIDDLEWARE, (new Definition(CommitTrackingMiddleware::class))->addTag('doctrine.middleware'));
    }

    private function compile(ContainerBuilder $container): void
    {
        (new HttpLogCommitTrackingPass())->process($container);
        (new MiddlewaresPass())->process($container);
    }
}
