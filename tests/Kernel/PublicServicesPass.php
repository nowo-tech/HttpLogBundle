<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Kernel;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Keeps the given (otherwise unused) services in the compiled test container. */
final readonly class PublicServicesPass implements CompilerPassInterface
{
    /** @param list<string> $ids */
    public function __construct(private array $ids)
    {
    }

    public function process(ContainerBuilder $container): void
    {
        foreach ($this->ids as $id) {
            if ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            } elseif ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            }
        }
    }
}
