<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection;

use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogConflictListener;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.22 (REQ-RUNTIME-003 rule 6): `conflict_response` options and the registration of the conflict answer.
 */
final class ConflictResponseExtensionTest extends TestCase
{
    public function testConflictResponseDefaultsToNullAndAccepts409Or503(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), []);
        self::assertSame(['status' => null, 'retry_after' => null, 'commit_tracking' => 'bundle'], $config['conflict_response']);

        $config = (new Processor())->processConfiguration(new Configuration(), [['conflict_response' => ['status' => 503, 'retry_after' => 3, 'commit_tracking' => 'off']]]);
        self::assertSame(['status' => 503, 'retry_after' => 3, 'commit_tracking' => 'off'], $config['conflict_response']);
    }

    public function testConflictResponseRejectsOtherStatuses(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be 409 or 503');

        (new Processor())->processConfiguration(new Configuration(), [['conflict_response' => ['status' => 500]]]);
    }

    public function testCommitTrackingRejectsUnknownModes(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['conflict_response' => ['commit_tracking' => 'host']]]);
    }

    /**
     * The answer follows transaction-doctrine's policy service by default (optional reference); the bundle's options
     * win when set; the commit-tracking middleware is tagged without a connection (scoped by HttpLogCommitTrackingPass).
     */
    public function testTheConflictAnswerIsRegistered(): void
    {
        $container = $this->load([]);

        $listener = $container->getDefinition(HttpLogConflictListener::class);
        $policy   = $listener->getArgument(0);
        self::assertInstanceOf(Reference::class, $policy);
        self::assertSame(ConflictResponsePolicy::class, (string) $policy);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $policy->getInvalidBehavior());
        self::assertSame(NowoHttpLogExtension::COMMIT_LEDGER_SERVICE, (string) $listener->getArgument(1));
        self::assertSame([
            ['event' => 'kernel.request', 'method' => 'onRequest', 'priority' => HttpLogConflictListener::REQUEST_PRIORITY],
            ['event' => 'kernel.exception', 'method' => 'onException', 'priority' => HttpLogConflictListener::PRIORITY],
        ], $listener->getTag('kernel.event_listener'));

        $middleware = $container->getDefinition(NowoHttpLogExtension::COMMIT_TRACKING_MIDDLEWARE);
        self::assertSame(CommitTrackingMiddleware::class, $middleware->getClass());
        self::assertSame([[]], $middleware->getTag('doctrine.middleware'));
        self::assertSame('bundle', $container->getParameter(NowoHttpLogExtension::COMMIT_TRACKING_PARAMETER));

        $container = $this->load(['commit_tracking' => 'off']);
        self::assertFalse($container->hasDefinition(NowoHttpLogExtension::COMMIT_TRACKING_MIDDLEWARE), 'off: no middleware');
        self::assertFalse($container->hasDefinition(NowoHttpLogExtension::COMMIT_LEDGER_SERVICE));
        self::assertNull($container->getDefinition(HttpLogConflictListener::class)->getArgument(1));

        $policy = $this->load(['status' => 503])->getDefinition(HttpLogConflictListener::class)->getArgument(0);
        self::assertInstanceOf(Definition::class, $policy);
        self::assertSame([503, ConflictResponsePolicy::DEFAULT_RETRY_AFTER], $policy->getArguments());

        $policy = $this->load(['retry_after' => 5])->getDefinition(HttpLogConflictListener::class)->getArgument(0);
        self::assertInstanceOf(Definition::class, $policy);
        self::assertSame([ConflictResponsePolicy::STATUS_CONFLICT, 5], $policy->getArguments());
    }

    public function testWithoutTheWebUiNothingIsRegistered(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpLogExtension())->load([['web_ui' => ['enabled' => false]]], $container);

        // the resource loader keeps an excluded class only as an abstract placeholder (container.excluded)
        self::assertFalse($container->hasDefinition(HttpLogConflictListener::class) && $container->getDefinition(HttpLogConflictListener::class)->hasTag('kernel.event_listener'));
        self::assertFalse($container->hasDefinition(NowoHttpLogExtension::COMMIT_TRACKING_MIDDLEWARE));
        self::assertFalse($container->hasParameter(NowoHttpLogExtension::COMMIT_TRACKING_PARAMETER));
    }

    /**
     * @param array<string, mixed> $conflictResponse
     */
    private function load(array $conflictResponse): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoHttpLogExtension())->load([['security' => ['allow_unauthenticated' => true], 'conflict_response' => $conflictResponse]], $container);

        return $container;
    }
}
