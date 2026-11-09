<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\HttpLogBundle\DependencyInjection\Compiler\OutboundHttpClientPass;
use Nowo\HttpLogBundle\HttpClient\LoggingHttpClient;
use Nowo\HttpLogBundle\HttpClient\OutboundClientFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class OutboundHttpClientPassTest extends TestCase
{
    #[Test]
    public function decoratesTheSelectedClientsOutermost(): void
    {
        $container = $this->container(true, ['*'], ['excluded.client'], -1024);
        (new OutboundHttpClientPass())->process($container);

        $decorator = $container->getDefinition('http_client.nowo_http_log');
        self::assertSame(LoggingHttpClient::class, $decorator->getClass());
        self::assertSame(['http_client', null, -1024], $decorator->getDecoratedService());
        self::assertEquals([new Reference(OutboundClientFactory::class), 'create'], $decorator->getFactory());
        self::assertEquals([new Reference('http_client.nowo_http_log.inner'), 'http_client', null], $decorator->getArguments());
        self::assertTrue($decorator->hasTag('kernel.reset'));
        self::assertTrue($container->hasDefinition('clickup.client.nowo_http_log'));
        self::assertFalse($container->hasDefinition('excluded.client.nowo_http_log'));
    }

    #[Test]
    public function anExplicitListDecoratesOnlyThoseClients(): void
    {
        $container = $this->container(true, ['clickup.client'], [], 5);
        (new OutboundHttpClientPass())->process($container);

        self::assertFalse($container->hasDefinition('http_client.nowo_http_log'));
        self::assertSame(['clickup.client', null, 5], $container->getDefinition('clickup.client.nowo_http_log')->getDecoratedService());
    }

    #[Test]
    public function disabledOrUnconfiguredDecoratesNothing(): void
    {
        $disabled = $this->container(false, ['*'], [], -1024);
        (new OutboundHttpClientPass())->process($disabled);
        self::assertFalse($disabled->hasDefinition('http_client.nowo_http_log'));

        $bare = new ContainerBuilder();
        $bare->register('http_client')->addTag('http_client.client');
        (new OutboundHttpClientPass())->process($bare);
        self::assertFalse($bare->hasDefinition('http_client.nowo_http_log'));

        $odd = $this->container(true, 'not a list', 'not a list', 'not an int');
        (new OutboundHttpClientPass())->process($odd);
        self::assertFalse($odd->hasDefinition('http_client.nowo_http_log'));
    }

    #[Test]
    public function aNonIntegerPriorityFallsBackToOutermost(): void
    {
        $container = $this->container(true, ['*'], [], 'x');
        (new OutboundHttpClientPass())->process($container);

        self::assertSame(-1024, $container->getDefinition('http_client.nowo_http_log')->getDecoratedService()[2] ?? null);
    }

    private function container(bool $enabled, mixed $clients, mixed $exclude, mixed $priority): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_http_log.outbound.enabled', $enabled);
        $container->setParameter('nowo_http_log.outbound.clients', $clients);
        $container->setParameter('nowo_http_log.outbound.exclude_clients', $exclude);
        $container->setParameter('nowo_http_log.outbound.decoration_priority', $priority);
        foreach (['http_client', 'clickup.client', 'excluded.client'] as $id) {
            $container->register($id)->addTag('http_client.client');
        }

        return $container;
    }
}
