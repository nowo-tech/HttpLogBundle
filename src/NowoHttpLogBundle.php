<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle;

use Nowo\HttpLogBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class NowoHttpLogBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TwigPathsPass());
    }

    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new NowoHttpLogExtension();
        }

        return $this->extension;
    }
}
