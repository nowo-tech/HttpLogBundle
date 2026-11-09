<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\Doctrine\UsageColumnsListener;
use Nowo\HttpLogBundle\HttpClient\HttpLogHttpClientWrapper;
use Nowo\HttpLogBundle\HttpClient\OutboundCapturePolicy;
use Nowo\HttpLogBundle\HttpClient\OutboundClientFactory;
use Nowo\HttpLogBundle\Mailer\MailerCaptureListener;
use Nowo\HttpLogBundle\MessageHandler\PersistHttpLogHandler;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use Nowo\HttpLogBundle\Usage\UsageBufferFlushSubscriber;
use Nowo\HttpLogBundle\Usage\UsageCaptureSink;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;
use Symfony\Component\DependencyInjection\Reference;

use const ARRAY_FILTER_USE_KEY;

/**
 * HL-1 S1: the feature blocks are off by default and register nothing but the inert context API; enabling one
 * registers its services; the configuration fails closed.
 */
final class UsageExtensionTest extends TestCase
{
    /** Services of the feature that must not exist for a host that enables nothing (BC matrix §2 a). */
    private const FEATURE_SERVICES = [
        UsageColumnsListener::class,
        UsageEntryWriter::class,
        UsageCaptureSink::class,
        HttpLogBuffer::class,
        UsageBufferFlushSubscriber::class,
    ];

    #[Test]
    public function defaultsRegisterNoFeatureService(): void
    {
        $container = $this->load([]);

        foreach (self::FEATURE_SERVICES as $id) {
            self::assertFalse($this->registered($container, $id), $id);
        }
        foreach ($container->getDefinitions() as $id => $definition) {
            self::assertNull($definition->getDecoratedService(), 'no decorator: ' . $id);
        }
        self::assertFalse($container->getParameter('nowo_http_log.usage.enabled'));
        self::assertSame([], $container->getParameter('nowo_http_log.usage.channels'));
        self::assertSame([], $container->getParameter('nowo_http_log.usage.channel_retention'));
        self::assertSame([], $container->getDefinition(PersistHttpLogHandler::class)->getArguments() === [] ? [] : array_filter($container->getDefinition(PersistHttpLogHandler::class)->getArguments(), static fn (int|string $k): bool => $k === '$usageWriter', ARRAY_FILTER_USE_KEY));

        // inert API, always there
        self::assertTrue($container->getDefinition(HttpLogContext::class)->isPublic());
        self::assertTrue($container->hasDefinition(NowoHttpLogExtension::CONTEXT_MIDDLEWARE));
        self::assertFalse($container->getDefinition(NowoHttpLogExtension::CONTEXT_MIDDLEWARE)->isPublic(), 'private: removed unless a bus uses it');
    }

    #[Test]
    public function middlewareCanBeTurnedOff(): void
    {
        self::assertFalse($this->registered($this->load(['messenger' => ['middleware' => false]]), NowoHttpLogExtension::CONTEXT_MIDDLEWARE));
    }

    #[Test]
    public function enablingABlockRegistersTheStorageServices(): void
    {
        $container = $this->load(['mailer' => ['enabled' => true, 'retention_days' => null], 'async' => false]);

        foreach (self::FEATURE_SERVICES as $id) {
            self::assertTrue($this->registered($container, $id), $id);
        }
        self::assertSame([['event' => 'loadClassMetadata', 'priority' => 100]], $container->getDefinition(UsageColumnsListener::class)->getTag('doctrine.event_listener'));
        self::assertTrue($container->getDefinition(UsageBufferFlushSubscriber::class)->hasTag('kernel.event_subscriber'));
        self::assertTrue($container->getDefinition(HttpLogBuffer::class)->hasTag('kernel.reset'));
        self::assertFalse($container->getDefinition(UsageCaptureSink::class)->getArgument(1));
        self::assertTrue($container->getParameter('nowo_http_log.usage.enabled'));
        self::assertSame(['mailer'], $container->getParameter('nowo_http_log.usage.channels'));
        self::assertSame(['mailer' => null], $container->getParameter('nowo_http_log.usage.channel_retention'));
        self::assertArrayHasKey('$usageWriter', $container->getDefinition(PersistHttpLogHandler::class)->getArguments());
    }

    #[Test]
    public function outboundHasItsOwnRetentionScope(): void
    {
        $container = $this->load(['outbound' => ['enabled' => true, 'retention_days' => 7], 'mailer' => ['enabled' => true]]);

        self::assertSame(['outbound', 'mailer'], $container->getParameter('nowo_http_log.usage.channels'));
        self::assertSame(['outbound' => 7, 'mailer' => 30], $container->getParameter('nowo_http_log.usage.channel_retention'));
        self::assertTrue($container->getParameter('nowo_http_log.outbound.enabled'));
    }

    #[Test]
    public function outboundRegistersItsPolicyFactoryAndAnActiveWrapper(): void
    {
        $container = $this->load(['outbound' => [
            'enabled'         => true,
            'sampling_rate'   => 0.25,
            'record_unmapped' => false,
            'path_words'      => ['chat.postMessage'],
            'exclude_hosts'   => ['SQS.example'],
            'clients'         => ['clickup.client'],
            'exclude_clients' => ['x'],
            'providers'       => ['clickup' => ['clients' => ['clickup.client'], 'rollup' => false]],
        ]]);

        $policy = $container->getDefinition(OutboundCapturePolicy::class);
        self::assertSame(['clickup' => ['clients' => ['clickup.client'], 'hosts' => [], 'path_templates' => [], 'path_depth' => null, 'per_call' => true]], $policy->getArgument(0));
        self::assertSame([0.25, false, 2, ['chat.postMessage'], ['sqs.example']], [$policy->getArgument(1), $policy->getArgument(2), $policy->getArgument(3), $policy->getArgument(4), $policy->getArgument(5)]);
        self::assertArrayHasKey('$buffer', $container->getDefinition(HttpLogRecorder::class)->getArguments());
        self::assertTrue($container->hasDefinition(OutboundClientFactory::class));
        self::assertEquals(new Reference(OutboundClientFactory::class), $container->getDefinition(HttpLogHttpClientWrapper::class)->getArgument(0));
        self::assertSame(['clickup.client'], $container->getParameter('nowo_http_log.outbound.clients'));
        self::assertSame(['x'], $container->getParameter('nowo_http_log.outbound.exclude_clients'));
        self::assertSame(-1024, $container->getParameter('nowo_http_log.outbound.decoration_priority'));
    }

    #[Test]
    public function theWrapperIsInertWhileOutboundIsDisabledAndAbsentWithoutHttpClient(): void
    {
        $container = $this->load([]);
        self::assertTrue($container->getDefinition(HttpLogHttpClientWrapper::class)->isPublic());
        self::assertNull($container->getDefinition(HttpLogHttpClientWrapper::class)->getArgument(0));
        self::assertFalse($this->registered($container, OutboundClientFactory::class));

        $without = new ContainerBuilder();
        $without->setParameter('kernel.environment', 'test');
        (new NowoHttpLogExtension(['outbound' => ['Nowo\\Missing\\HttpClient', 'symfony/http-client'], 'mailer' => NowoHttpLogExtension::REQUIRED_SYMBOLS['mailer']]))
            ->load([['security' => ['allow_unauthenticated' => true]]], $without);
        self::assertFalse($this->registered($without, HttpLogHttpClientWrapper::class));
    }

    #[Test]
    public function mailerRegistersItsListenerWithTheSchemeMap(): void
    {
        $container = $this->load(['mailer' => ['enabled' => true, 'providers' => ['resend' => ['schemes' => ['resend', 'resend+api']], 'ses' => ['schemes' => ['ses+smtp']]]]]);
        $listener  = $container->getDefinition(MailerCaptureListener::class);

        self::assertSame(['resend' => 'resend', 'resend+api' => 'resend', 'ses+smtp' => 'ses'], $listener->getArgument(3));
        self::assertTrue($listener->hasTag('kernel.event_subscriber'));
        self::assertTrue($listener->hasTag('kernel.reset'));
        self::assertFalse($this->registered($this->load([]), MailerCaptureListener::class));
    }

    #[Test]
    public function usageColumnsTightenTheTablePrefixLimit(): void
    {
        $prefix = 'p' . str_repeat('x', 34) . '_'; // 36 characters: fine for 1.14 names, too long for the usage indexes
        $this->load(['doctrine' => ['table_prefix' => $prefix]]);

        $this->expectException(InvalidConfigurationException::class);
        $this->load(['doctrine' => ['table_prefix' => $prefix], 'mailer' => ['enabled' => true]]);
    }

    #[Test]
    public function enabledMustBeALiteral(): void
    {
        $bag       = new EnvPlaceholderParameterBag();
        $container = new ContainerBuilder($bag);
        $container->setParameter('kernel.environment', 'test');
        $placeholder = $bag->get('env(bool:HTTP_LOG_OUTBOUND)');
        BaseNode::setPlaceholderUniquePrefix($bag->getEnvPlaceholderUniquePrefix());

        try {
            $this->expectException(InvalidConfigurationException::class);
            $this->expectExceptionMessage('nowo_http_log.outbound.enabled must be a literal boolean');
            (new NowoHttpLogExtension())->load([['security' => ['allow_unauthenticated' => true], 'outbound' => ['enabled' => $placeholder]]], $container);
        } finally {
            BaseNode::resetPlaceholders();
        }
    }

    #[Test]
    public function aMissingPackageFailsTheBuild(): void
    {
        $extension = new NowoHttpLogExtension([
            'outbound' => ['Nowo\\Missing\\HttpClient', 'symfony/http-client'],
            'mailer'   => ['Nowo\\Missing\\Mailer', 'symfony/mailer'],
        ]);
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_http_log.mailer.enabled requires symfony/mailer.');
        $extension->load([['security' => ['allow_unauthenticated' => true], 'mailer' => ['enabled' => true]]], $container);
    }

    #[Test]
    public function configurationDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertSame([
            'enabled'             => false,
            'clients'             => ['*'],
            'exclude_clients'     => [],
            'sampling_rate'       => 1.0,
            'retention_days'      => 30,
            'record_unmapped'     => true,
            'default_path_depth'  => 2,
            'path_words'          => [],
            'exclude_hosts'       => [],
            'decoration_priority' => -1024,
            'providers'           => [],
        ], $config['outbound']);
        self::assertSame(['enabled' => false, 'retention_days' => 30, 'providers' => [], 'rollup' => true], $config['mailer']);
        self::assertSame(['middleware' => true, 'propagate_tenant' => true], $config['messenger']);
    }

    #[Test]
    public function aValidProviderMapIsNormalized(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'outbound' => ['providers' => [
                'clickup' => ['clients' => ['clickup.client'], 'hosts' => ['API.ClickUp.com'], 'path_templates' => ['/api/v2/task/{id}']],
                's3'      => ['hosts' => ['*.amazonaws.com', 'minio.internal:9000', '[::1]:9000'], 'path_depth' => 1],
            ]],
            'mailer' => ['providers' => ['resend' => ['schemes' => ['resend', 'resend+api']]]],
        ]]);

        self::assertSame(['api.clickup.com'], $config['outbound']['providers']['clickup']['hosts']);
        self::assertNull($config['outbound']['providers']['clickup']['path_depth']);
        self::assertTrue($config['outbound']['providers']['clickup']['per_call']);
        self::assertSame(1, $config['outbound']['providers']['s3']['path_depth']);
        self::assertSame(['resend', 'resend+api'], $config['mailer']['providers']['resend']['schemes']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidConfigs(): iterable
    {
        yield 'provider key' => [['outbound' => ['providers' => ['Bad Key' => ['hosts' => ['a.example']]]]]];
        yield 'provider without clients and hosts' => [['outbound' => ['providers' => ['p' => ['path_depth' => 1]]]]];
        yield 'host with scheme' => [['outbound' => ['providers' => ['p' => ['hosts' => ['https://a.example']]]]]];
        yield 'host with path' => [['outbound' => ['providers' => ['p' => ['hosts' => ['a.example/x']]]]]];
        yield 'port wildcard' => [['outbound' => ['providers' => ['p' => ['hosts' => ['a.example:*']]]]]];
        yield 'two wildcards' => [['outbound' => ['providers' => ['p' => ['hosts' => ['*.*.example']]]]]];
        yield 'inner wildcard' => [['outbound' => ['providers' => ['p' => ['hosts' => ['a.*.example']]]]]];
        yield 'template without slash' => [['outbound' => ['providers' => ['p' => ['hosts' => ['a.example'], 'path_templates' => ['v2/{id}']]]]]];
        yield 'template with query' => [['outbound' => ['providers' => ['p' => ['hosts' => ['a.example'], 'path_templates' => ['/v2?x={id}']]]]]];
        yield 'template with partial placeholder' => [['outbound' => ['providers' => ['p' => ['hosts' => ['a.example'], 'path_templates' => ['/v2/user-{id}']]]]]];
        yield 'template with 17 segments' => [['outbound' => ['providers' => ['p' => ['hosts' => ['a.example'], 'path_templates' => [str_repeat('/a', 17)]]]]]];
        yield 'path depth' => [['outbound' => ['default_path_depth' => 17]]];
        yield 'path word' => [['outbound' => ['path_words' => ['has space']]]];
        yield 'excluded host with scheme' => [['outbound' => ['exclude_hosts' => ['redis://cache']]]];
        yield 'sampling' => [['outbound' => ['sampling_rate' => 1.5]]];
        yield 'retention' => [['outbound' => ['retention_days' => -1]]];
        yield 'mailer retention' => [['mailer' => ['retention_days' => 'x']]];
        yield 'mailer provider key' => [['mailer' => ['providers' => ['Bad' => ['schemes' => ['smtp']]]]]];
        yield 'mailer scheme' => [['mailer' => ['providers' => ['p' => ['schemes' => ['SMTP://']]]]]];
        yield 'mailer no scheme' => [['mailer' => ['providers' => ['p' => ['schemes' => []]]]]];
    }

    /** @param array<string, mixed> $config */
    #[Test]
    #[DataProvider('invalidConfigs')]
    public function invalidConfigurationFailsClosed(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [$config]);
    }

    /** Defined by the extension (not just the `container.excluded` hint of the resource loader). */
    private function registered(ContainerBuilder $container, string $id): bool
    {
        return $container->hasDefinition($id) && !$container->getDefinition($id)->hasTag('container.excluded');
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
