<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection;

use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\Correlation\CorrelationIds;
use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\Doctrine\CorrelationColumnsListener;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.27: the correlation provider is read only when the host registers one, the columns are mapped only on demand.
 */
final class CorrelationExtensionTest extends TestCase
{
    #[Test]
    public function defaultsReadAnOptionalProviderAndMapNoColumn(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]])['correlation'];
        self::assertSame(['use_provider' => true, 'columns' => false, 'upstream_headers' => ['X-Request-Id', 'X-Correlation-Id', 'traceparent']], $config);

        $container = $this->load([]);
        $arguments = $container->getDefinition(CorrelationIds::class)->getArguments();
        self::assertInstanceOf(Reference::class, $arguments[0]);
        self::assertSame(CorrelationIdProviderInterface::class, (string) $arguments[0]);
        self::assertSame(ContainerInterface::NULL_ON_INVALID_REFERENCE, $arguments[0]->getInvalidBehavior(), 'no provider: null, the 1.26 ids');
        self::assertSame('request_stack', (string) $arguments[1]);
        self::assertSame(Configuration::DEFAULT_UPSTREAM_HEADERS, $arguments[2]);

        $subscriber = $container->getDefinition(HttpLogSubscriber::class);
        self::assertSame(CorrelationIds::class, (string) $subscriber->getArgument('$correlation'));
        self::assertFalse($subscriber->getArgument('$captureUpstream'));
        self::assertTrue(!$container->hasDefinition(CorrelationColumnsListener::class) || $container->getDefinition(CorrelationColumnsListener::class)->hasTag('container.excluded'), 'only the resource loader\'s excluded hint');
        self::assertSame(CorrelationIds::class, (string) $container->getDefinition(HttpLogContext::class)->getArgument(0));
    }

    #[Test]
    public function useProviderFalseIgnoresTheHostProvider(): void
    {
        $container = $this->load(['correlation' => ['use_provider' => false]]);

        self::assertNull($container->getDefinition(CorrelationIds::class)->getArgument(0));
    }

    #[Test]
    public function columnsRegisterTheListenerAndTheUpstreamCapture(): void
    {
        $container = $this->load(['correlation' => ['columns' => true, 'upstream_headers' => ['traceparent']]]);

        $listener = $container->getDefinition(CorrelationColumnsListener::class);
        self::assertSame([['event' => 'loadClassMetadata', 'priority' => 100]], $listener->getTag('doctrine.event_listener'));
        self::assertTrue($container->getDefinition(HttpLogSubscriber::class)->getArgument('$captureUpstream'));
        self::assertSame(['traceparent'], $container->getDefinition(CorrelationIds::class)->getArgument(2));
    }

    #[Test]
    public function columnsMustBeALiteralBoolean(): void
    {
        $bag       = new EnvPlaceholderParameterBag();
        $container = new ContainerBuilder($bag);
        $container->setParameter('kernel.environment', 'test');
        $placeholder = $bag->get('env(bool:HTTP_LOG_CORRELATION)');
        BaseNode::setPlaceholderUniquePrefix($bag->getEnvPlaceholderUniquePrefix());

        try {
            $this->expectException(InvalidConfigurationException::class);
            $this->expectExceptionMessage('nowo_http_log.correlation.columns must be a literal boolean');
            (new NowoHttpLogExtension())->load([['security' => ['allow_unauthenticated' => true], 'correlation' => ['columns' => $placeholder]]], $container);
        } finally {
            BaseNode::resetPlaceholders();
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function invalidConfigurations(): iterable
    {
        yield 'header with a space' => [['upstream_headers' => ['X Request']]];
        yield 'header with a colon' => [['upstream_headers' => ['X-Request-Id:']]];
        yield 'not a string' => [['upstream_headers' => [['x']]]];
        yield 'too many headers' => [['upstream_headers' => ['a', 'b', 'c', 'd', 'e']]];
    }

    /** @param array<string, mixed> $correlation */
    #[Test]
    #[DataProvider('invalidConfigurations')]
    public function invalidUpstreamHeadersFailClosed(array $correlation): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['correlation' => $correlation]]);
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
