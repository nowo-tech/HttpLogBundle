<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\HttpLogBundle\Controller\HttpLogAdminController;
use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\Security\ConfigurableHttpLogAccessChecker;
use Nowo\HttpLogBundle\Security\HttpLogAccessCheckerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;

use function dirname;

final class NowoHttpLogExtensionTest extends TestCase
{
    #[Test]
    public function getAliasReturnsConfigurationAlias(): void
    {
        $extension = new NowoHttpLogExtension();

        self::assertSame(Configuration::ALIAS, $extension->getAlias());
        self::assertSame('nowo_http_log', $extension->getAlias());
    }

    #[Test]
    public function configurationProcessesEmptyConfig(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertTrue($config['enabled']);
        self::assertSame(30, $config['retention']['days']);
        self::assertTrue($config['web_ui']['enabled']);
    }

    #[Test]
    public function loadSetsParametersFromDefaults(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new NowoHttpLogExtension());
        $container->setParameter('kernel.environment', 'test');

        $extension = new NowoHttpLogExtension();
        $extension->load([['security' => ['allow_unauthenticated' => true]]], $container);

        self::assertTrue($container->hasParameter('nowo_http_log.enabled'));
        self::assertTrue($container->getParameter('nowo_http_log.enabled'));
        self::assertSame(30, $container->getParameter('nowo_http_log.retention')['days']);
        self::assertTrue($container->hasAlias(HttpLogAccessCheckerInterface::class));
        self::assertTrue($container->hasDefinition('nowo_http_log.access_checker.default'));
        self::assertSame(
            ConfigurableHttpLogAccessChecker::class,
            $container->getDefinition('nowo_http_log.access_checker.default')->getClass(),
        );
    }

    #[Test]
    public function loadThrowsWhenWebUiRequiresSecurityBundle(): void
    {
        $container = new ContainerBuilder();
        $extension = new NowoHttpLogExtension();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires symfony/security-bundle');

        $extension->load([[
            'web_ui'   => ['enabled' => true],
            'security' => ['allow_unauthenticated' => false],
        ]], $container);
    }

    #[Test]
    public function prependAddsFrameworkAndDoctrineDefaultsWithoutBundleConfig(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('framework'));
        $container->registerExtension($this->createExtension('doctrine'));

        (new NowoHttpLogExtension())->prepend($container);

        self::assertSame([
            [
                'translator' => [
                    'paths'     => [dirname(__DIR__, 3) . '/src/DependencyInjection/../Resources/translations'],
                    'fallbacks' => ['en'],
                ],
            ],
        ], $container->getExtensionConfig('framework'));
        self::assertSame([
            [
                'orm' => [
                    'mappings' => [
                        'NowoHttpLogBundle' => [
                            'type'      => 'attribute',
                            'is_bundle' => true,
                        ],
                    ],
                ],
            ],
        ], $container->getExtensionConfig('doctrine'));
    }

    #[Test]
    public function prependAddsTwigGlobalsWhenWebUiIsEnabled(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('twig'));
        $container->registerExtension(new NowoHttpLogExtension());
        $container->loadFromExtension('nowo_http_log', [
            'security' => ['allow_unauthenticated' => true],
            'web_ui'   => [
                'enabled'         => true,
                'layout_template' => 'base.html.twig',
                'css_framework'   => 'bootstrap5',
                'path_prefix'     => '/http-log',
                'page_size'       => 75,
            ],
        ]);

        (new NowoHttpLogExtension())->prepend($container);

        self::assertSame([
            [
                'globals' => [
                    'nowo_http_log_layout_template' => 'base.html.twig',
                    'nowo_http_log_css_framework'   => 'bootstrap5',
                    'nowo_http_log_path_prefix'     => '/http-log',
                    'nowo_http_log_page_size'       => 75,
                ],
            ],
        ], $container->getExtensionConfig('twig'));
    }

    #[Test]
    public function prependEscapesAtSignInLayoutTemplateForDependencyInjection(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('twig'));
        $container->registerExtension(new NowoHttpLogExtension());
        $container->loadFromExtension('nowo_http_log', [
            'security' => ['allow_unauthenticated' => true],
            'web_ui'   => [
                'enabled'         => true,
                'layout_template' => '@NowoHttpLogBundle/layout.html.twig',
            ],
        ]);

        (new NowoHttpLogExtension())->prepend($container);

        $twigConfig = $container->getExtensionConfig('twig');
        self::assertSame(
            '@@NowoHttpLogBundle/layout.html.twig',
            $twigConfig[0]['globals']['nowo_http_log_layout_template'],
        );
    }

    #[Test]
    public function loadAcceptsSecurityViaKernelBundlesWhenExtensionMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['SecurityBundle' => SecurityBundle::class]);

        (new NowoHttpLogExtension())->load([[
            'web_ui'   => ['enabled' => true],
            'security' => ['allow_unauthenticated' => false],
        ]], $container);

        self::assertTrue($container->hasParameter('nowo_http_log.enabled'));
    }

    #[Test]
    public function loadUsesCustomAccessCheckerAliasWhenConfigured(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('security'));

        (new NowoHttpLogExtension())->load([[
            'security' => [
                'allow_unauthenticated' => true,
                'access_checker'        => 'app.http_log_checker',
            ],
        ]], $container);

        self::assertSame('app.http_log_checker', (string) $container->getAlias(HttpLogAccessCheckerInterface::class));
        self::assertFalse($container->hasDefinition('nowo_http_log.access_checker.default'));
    }

    #[Test]
    public function loadRemovesAdminControllerDefinitionWhenWebUiIsDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('security'));
        $container->setDefinition(
            HttpLogAdminController::class,
            new Definition(HttpLogAdminController::class),
        );

        (new NowoHttpLogExtension())->load([[
            'web_ui'   => ['enabled' => false],
            'security' => ['allow_unauthenticated' => true],
        ]], $container);

        self::assertFalse($container->hasDefinition(HttpLogAdminController::class));
    }

    #[Test]
    public function prependSeedsFormKitHttpLogProfileWhenMissing(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('nowo_form_kit'));

        (new NowoHttpLogExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        self::assertNotEmpty($configs);
        $merged = array_replace_recursive(...array_reverse($configs));
        self::assertSame('bootstrap', $merged['css_framework']);
        self::assertArrayHasKey('http_log', $merged['profiles']);
        self::assertSame('NowoHttpLogBundle', $merged['profiles']['http_log']['translation_domain']);
    }

    #[Test]
    public function prependDoesNotOverrideHostFormKitProfileOrCssFramework(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('nowo_form_kit'));
        $container->prependExtensionConfig('nowo_form_kit', [
            'css_framework' => 'tailwind',
            'profiles'      => [
                'http_log' => [
                    'alias' => 'custom',
                ],
            ],
        ]);

        (new NowoHttpLogExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_form_kit');
        // Host config is first; no seed prepended when both keys already present.
        self::assertCount(1, $configs);
        self::assertSame('tailwind', $configs[0]['css_framework']);
        self::assertSame('custom', $configs[0]['profiles']['http_log']['alias']);
    }

    #[Test]
    public function prependSeedsUiKitDefaultsFromWebUiCssFramework(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('nowo_ui_kit'));
        $container->registerExtension(new NowoHttpLogExtension());
        $container->loadFromExtension('nowo_http_log', [
            'security' => ['allow_unauthenticated' => true],
            'web_ui'   => [
                'css_framework' => 'tailwind',
            ],
        ]);

        (new NowoHttpLogExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_ui_kit');
        self::assertNotEmpty($configs);
        $merged = array_replace_recursive(...array_reverse($configs));
        self::assertSame('tailwind', $merged['css_framework']);
        self::assertSame('bootstrap-icons', $merged['icon_set']);
    }

    #[Test]
    public function prependSeedsOnlyMissingUiKitCssFramework(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('nowo_ui_kit'));
        $container->registerExtension(new NowoHttpLogExtension());
        $container->prependExtensionConfig('nowo_ui_kit', [
            'icon_set' => 'fontawesome',
        ]);
        $container->loadFromExtension('nowo_http_log', [
            'security' => ['allow_unauthenticated' => true],
            'web_ui'   => [
                'css_framework' => 'foundation',
            ],
        ]);

        (new NowoHttpLogExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_ui_kit');
        $merged  = array_replace_recursive(...array_reverse($configs));
        self::assertSame('foundation', $merged['css_framework']);
        self::assertSame('fontawesome', $merged['icon_set']);
    }

    #[Test]
    public function prependDoesNotOverrideHostUiKitKeys(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('nowo_ui_kit'));
        $container->prependExtensionConfig('nowo_ui_kit', [
            'css_framework' => 'bootstrap4',
            'icon_set'      => 'fontawesome',
        ]);

        (new NowoHttpLogExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_ui_kit');
        self::assertCount(1, $configs);
        self::assertSame('bootstrap4', $configs[0]['css_framework']);
        self::assertSame('fontawesome', $configs[0]['icon_set']);
    }

    #[Test]
    public function prependSeedsOnlyMissingUiKitIconSet(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('nowo_ui_kit'));
        $container->registerExtension(new NowoHttpLogExtension());
        $container->prependExtensionConfig('nowo_ui_kit', [
            'css_framework' => 'bootstrap5',
        ]);
        $container->loadFromExtension('nowo_http_log', [
            'security' => ['allow_unauthenticated' => true],
        ]);

        (new NowoHttpLogExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_ui_kit');
        $merged  = array_replace_recursive(...array_reverse($configs));
        self::assertSame('bootstrap5', $merged['css_framework']);
        self::assertSame('bootstrap-icons', $merged['icon_set']);
    }

    private function createExtension(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(private readonly string $aliasName)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return $this->aliasName;
            }
        };
    }
}
