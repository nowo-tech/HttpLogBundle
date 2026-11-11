<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\DependencyInjection\Compiler;

use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolution;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolverInterface;
use Nowo\HttpLogBundle\Inbound\HttpRequestContextResolverInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function class_exists;
use function is_a;
use function is_string;

/**
 * Reports, once per container build, every request-context resolver that implements only the deprecated
 * {@see HttpLogRequestContextResolverInterface} (REQ-ARCH-001 rule 3; removed in 2.0). The resolver keeps working: the
 * pass changes nothing in the container.
 */
final class DeprecatedRequestContextResolverPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->findTaggedServiceIds(HttpLogRequestContextResolution::RESOLVER_TAG) as $id => $tags) {
            $class = $container->getParameterBag()->resolveValue($container->getDefinition($id)->getClass() ?? $id);
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }
            if (is_a($class, HttpLogRequestContextResolverInterface::class, true) && !is_a($class, HttpRequestContextResolverInterface::class, true)) {
                trigger_deprecation('nowo-tech/http-log-bundle', '1.19', 'The request-context resolver "%s" implements only "%s"; implement "%s" instead (removed in 2.0).', $class, HttpLogRequestContextResolverInterface::class, HttpRequestContextResolverInterface::class);
            }
        }
    }
}
