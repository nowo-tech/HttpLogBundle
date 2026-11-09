<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\DependencyInjection\Compiler;

use Nowo\HttpLogBundle\HttpClient\LoggingHttpClient;
use Nowo\HttpLogBundle\HttpClient\OutboundClientFactory;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function in_array;
use function is_array;
use function is_int;

/**
 * Decorates every service tagged `http_client.client` (`http_client` and each scoped client) that
 * `outbound.clients` / `outbound.exclude_clients` select with a {@see LoggingHttpClient}, at
 * `outbound.decoration_priority` (default -1024: outermost, outside RetryableHttpClient and tracing decorators).
 * Does nothing unless `outbound.enabled`.
 */
final class OutboundHttpClientPass implements CompilerPassInterface
{
    public const TAG = 'http_client.client';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_http_log.outbound.enabled') || $container->getParameter('nowo_http_log.outbound.enabled') !== true) {
            return;
        }

        $clients  = $container->getParameter('nowo_http_log.outbound.clients');
        $exclude  = $container->getParameter('nowo_http_log.outbound.exclude_clients');
        $priority = $container->getParameter('nowo_http_log.outbound.decoration_priority');
        $clients  = is_array($clients) ? $clients : [];
        $exclude  = is_array($exclude) ? $exclude : [];
        $priority = is_int($priority) ? $priority : -1024;

        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $id) {
            if ((!in_array('*', $clients, true) && !in_array($id, $clients, true)) || in_array($id, $exclude, true)) {
                continue;
            }

            $decorator = $id . '.nowo_http_log';
            $container->register($decorator, LoggingHttpClient::class)
                ->setFactory([new Reference(OutboundClientFactory::class), 'create'])
                ->setArguments([new Reference($decorator . '.inner'), $id, null])
                ->setDecoratedService($id, null, $priority)
                ->addTag('kernel.reset', ['method' => 'reset', 'on_invalid' => 'ignore']);
        }
    }
}
