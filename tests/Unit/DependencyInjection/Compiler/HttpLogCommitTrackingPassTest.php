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
 * Audit UE-CF-1: the bundle's commit-tracking middleware never wraps the host's other connections by default, and is
 * not registered at all when transaction-doctrine's commit_detection already counts commits.
 */
#[CoversClass(HttpLogCommitTrackingPass::class)]
final class HttpLogCommitTrackingPassTest extends TestCase
{
    private const MIDDLEWARE = NowoHttpLogExtension::COMMIT_TRACKING_MIDDLEWARE;

    public function testByDefaultOnlyTheConnectionOfTheDefaultEntityManagerIsWrapped(): void
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

        (new HttpLogCommitTrackingPass())->process($container);

        self::assertFalse($container->hasDefinition(self::MIDDLEWARE));
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

    private function compile(ContainerBuilder $container): void
    {
        (new HttpLogCommitTrackingPass())->process($container);
        (new MiddlewaresPass())->process($container);
    }
}
