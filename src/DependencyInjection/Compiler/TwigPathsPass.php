<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function dirname;
use function is_dir;
use function is_string;
use function rtrim;

/**
 * REQ-TWIG-001: application overrides win over bundle templates.
 */
final class TwigPathsPass implements CompilerPassInterface
{
    private const TWIG_NAMESPACE = 'NowoHttpLogBundle';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_http_log.web_ui.enabled')) {
            return;
        }

        if (!$container->getParameter('nowo_http_log.web_ui.enabled')) {
            return;
        }

        $loaderId = $this->getNativeLoaderServiceId($container);
        if ($loaderId === null) {
            return;
        }

        $viewsPath  = dirname(__DIR__, 2) . '/Resources/views';
        $definition = $container->getDefinition($loaderId);

        if ($container->hasParameter('kernel.project_dir')) {
            $projectDirParam = $container->getParameter('kernel.project_dir');
            if (is_string($projectDirParam)) {
                $projectDir   = rtrim($projectDirParam, '/\\');
                $overridePath = $projectDir . '/templates/bundles/NowoHttpLogBundle';
                if (is_dir($overridePath)) {
                    $definition->addMethodCall('prependPath', [$overridePath, self::TWIG_NAMESPACE]);
                }
            }
        }

        $definition->addMethodCall('addPath', [$viewsPath, self::TWIG_NAMESPACE]);
    }

    private function getNativeLoaderServiceId(ContainerBuilder $container): ?string
    {
        if ($container->hasAlias('twig.loader.native')) {
            $resolved = $this->resolveDefinitionId($container, (string) $container->getAlias('twig.loader.native'));
            if ($resolved !== null) {
                return $resolved;
            }
        }

        if ($container->hasDefinition('twig.loader.native')) {
            return 'twig.loader.native';
        }

        if ($container->hasDefinition('twig.loader.native_filesystem')) {
            return 'twig.loader.native_filesystem';
        }

        return null;
    }

    private function resolveDefinitionId(ContainerBuilder $container, string $id): ?string
    {
        for ($i = 0; $i < 32 && $container->hasAlias($id); ++$i) {
            $id = (string) $container->getAlias($id);
        }

        return $container->hasDefinition($id) ? $id : null;
    }
}
