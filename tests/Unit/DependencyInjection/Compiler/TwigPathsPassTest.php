<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\HttpLogBundle\DependencyInjection\Compiler\TwigPathsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Twig\Loader\FilesystemLoader;

use function dirname;

final class TwigPathsPassTest extends TestCase
{
    public function testSkipsWhenWebUiFlagParameterIsMissing(): void
    {
        $container = new ContainerBuilder();

        (new TwigPathsPass())->process($container);

        self::assertFalse($container->hasDefinition('twig.loader.native'));
    }

    public function testSkipsWhenWebUiDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_http_log.web_ui.enabled', false);

        (new TwigPathsPass())->process($container);

        self::assertFalse($container->hasDefinition('twig.loader.native'));
    }

    public function testSkipsWhenTwigLoaderMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_http_log.web_ui.enabled', true);

        (new TwigPathsPass())->process($container);

        self::assertFalse($container->hasDefinition('twig.loader.native'));
    }

    public function testAddsBundleViewsPathToNativeLoader(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_http_log.web_ui.enabled', true);
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        $definition = new Definition(FilesystemLoader::class);
        $container->setDefinition('twig.loader.native', $definition);

        (new TwigPathsPass())->process($container);

        $calls = $definition->getMethodCalls();
        self::assertNotEmpty($calls);
        self::assertSame('addPath', $calls[array_key_last($calls)][0]);
        self::assertSame('NowoHttpLogBundle', $calls[array_key_last($calls)][1][1]);
    }

    public function testPrependsOverridePathWhenDirectoryExists(): void
    {
        $projectDir = sys_get_temp_dir() . '/http_log_twig_' . uniqid('', true);
        $override   = $projectDir . '/templates/bundles/NowoHttpLogBundle';
        mkdir($override, 0777, true);

        try {
            $container = new ContainerBuilder();
            $container->setParameter('nowo_http_log.web_ui.enabled', true);
            $container->setParameter('kernel.project_dir', $projectDir);

            $definition = new Definition(FilesystemLoader::class);
            $container->setDefinition('twig.loader.native_filesystem', $definition);

            (new TwigPathsPass())->process($container);

            $calls = $definition->getMethodCalls();
            self::assertSame('prependPath', $calls[0][0]);
            self::assertSame($override, $calls[0][1][0]);
        } finally {
            @rmdir($override);
            @rmdir(dirname($override));
            @rmdir(dirname($override, 2));
            @rmdir($projectDir);
        }
    }

    public function testResolvesTwigLoaderAlias(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_http_log.web_ui.enabled', true);
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());

        $definition = new Definition(FilesystemLoader::class);
        $container->setDefinition('twig.loader.native_filesystem', $definition);
        $container->setAlias('twig.loader.native', 'twig.loader.native_filesystem');

        (new TwigPathsPass())->process($container);

        self::assertNotEmpty($definition->getMethodCalls());
    }

    public function testResolvesTwigLoaderAliasChain(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_http_log.web_ui.enabled', true);
        $container->setDefinition('twig.loader.actual', new Definition(FilesystemLoader::class));
        $container->setAlias('twig.loader.first', 'twig.loader.actual');
        $container->setAlias('twig.loader.native', 'twig.loader.first');

        (new TwigPathsPass())->process($container);

        self::assertNotEmpty($container->getDefinition('twig.loader.actual')->getMethodCalls());
    }
}
