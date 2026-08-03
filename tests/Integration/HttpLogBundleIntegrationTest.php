<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use LogicException;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\NowoHttpLogBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class HttpLogBundleIntegrationTest extends TestCase
{
    public function testExtensionLoadsDefaultConfiguration(): void
    {
        $container = $this->createContainer();
        (new NowoHttpLogExtension())->load([[]], $container);

        self::assertTrue($container->hasParameter('nowo_http_log.enabled'));
        self::assertTrue($container->getParameter('nowo_http_log.enabled'));
        self::assertTrue($container->hasParameter('nowo_http_log.capture'));
    }

    public function testBundleRegistersExtensionAndCompilerPass(): void
    {
        $bundle    = new NowoHttpLogBundle();
        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(NowoHttpLogExtension::class, $extension);
        self::assertSame('nowo_http_log', $extension->getAlias());

        $container = new ContainerBuilder();
        $bundle->build($container);
        self::assertNotEmpty($container->getCompilerPassConfig()->getPasses());
    }

    public function testExtensionRequiresSecurityWhenWebUiAndNotAllowUnauthenticated(): void
    {
        $this->expectException(LogicException::class);

        $container = new ContainerBuilder();
        (new NowoHttpLogExtension())->load([[
            'web_ui'   => ['enabled' => true],
            'security' => ['allow_unauthenticated' => false],
        ]], $container);
    }

    public function testExtensionSkipsSecurityRequirementWhenAllowUnauthenticated(): void
    {
        $container = new ContainerBuilder();
        (new NowoHttpLogExtension())->load([[
            'web_ui'   => ['enabled' => true],
            'security' => ['allow_unauthenticated' => true],
        ]], $container);

        self::assertTrue($container->hasParameter('nowo_http_log.security.allow_unauthenticated'));
        self::assertTrue($container->getParameter('nowo_http_log.security.allow_unauthenticated'));
    }

    private function createContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'security';
            }
        });

        return $container;
    }
}
