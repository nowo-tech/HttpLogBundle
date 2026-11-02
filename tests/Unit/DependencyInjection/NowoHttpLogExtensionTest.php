<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use LogicException;
use Nowo\HttpLogBundle\Controller\HttpLogAdminController;
use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\Doctrine\TablePrefixListener;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogSubscriber;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataEraser;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataEraserInterface;
use Nowo\HttpLogBundle\Security\ConfigurableHttpLogAccessChecker;
use Nowo\HttpLogBundle\Security\HttpLogAccessCheckerInterface;
use Nowo\HttpLogBundle\View\AdminChrome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;

use function dirname;
use function str_repeat;

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
    public function tablePrefixListenerIsRemovedWithoutPrefix(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        (new NowoHttpLogExtension())->load([['security' => ['allow_unauthenticated' => true]]], $container);

        self::assertSame('', $container->getParameter('nowo_http_log.doctrine.table_prefix'));
        self::assertSame('nowo_http_log_', $container->getParameter('nowo_http_log.doctrine.table_name_prefix'));
        self::assertFalse($container->hasDefinition(TablePrefixListener::class));
    }

    #[Test]
    public function tablePrefixListenerIsRegisteredWithTableNamePrefix(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        (new NowoHttpLogExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'doctrine' => ['table_name_prefix' => 'http_log_'],
        ]], $container);

        self::assertSame('http_log_', $container->getParameter('nowo_http_log.doctrine.table_name_prefix'));
        $definition = $container->getDefinition(TablePrefixListener::class);
        self::assertSame('%nowo_http_log.doctrine.table_name_prefix%', $definition->getArgument('$tableNamePrefix'));
    }

    #[Test]
    public function entryTableParameterFollowsBothPrefixes(): void
    {
        self::assertSame('nowo_http_log_entry', $this->loadDoctrine([])->getParameter('nowo_http_log.doctrine.entry_table'));
        self::assertSame('http_log_entry', $this->loadDoctrine(['table_name_prefix' => 'http_log_'])->getParameter('nowo_http_log.doctrine.entry_table'));
        self::assertSame('app_http_log_entry', $this->loadDoctrine(['table_name_prefix' => 'http_log_', 'table_prefix' => 'app_'])->getParameter('nowo_http_log.doctrine.entry_table'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidPrefixes(): iterable
    {
        yield 'leading digit' => [['table_name_prefix' => '1log_']];
        yield 'uppercase' => [['table_name_prefix' => 'Log_']];
        yield 'trailing newline' => [['table_name_prefix' => "log_\n"]];
        yield 'uppercase table_prefix' => [['table_prefix' => 'App_']];
        yield 'too long' => [['table_name_prefix' => 'l' . str_repeat('x', 47), 'table_prefix' => 'app_']];
        yield 'table_prefix too long for the indexes' => [['table_prefix' => 'a' . str_repeat('x', 39)]];
    }

    /**
     * @param array<string, mixed> $doctrine
     */
    #[Test]
    #[DataProvider('invalidPrefixes')]
    public function invalidPrefixesAreRejected(array $doctrine): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->loadDoctrine($doctrine);
    }

    #[Test]
    public function longestAllowedPrefixesFit(): void
    {
        // entry_id_seq leaves 51 characters; idx_http_log_status_code leaves 39 for table_prefix.
        self::assertSame(str_repeat('t', 39) . 'l' . str_repeat('x', 11) . 'entry', $this->loadDoctrine(['table_prefix' => str_repeat('t', 39), 'table_name_prefix' => 'l' . str_repeat('x', 11)])->getParameter('nowo_http_log.doctrine.entry_table'));
    }

    #[Test]
    public function envPlaceholdersAreRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('do not support %env()%');

        $bag       = new EnvPlaceholderParameterBag();
        $container = new ContainerBuilder($bag);
        $container->setParameter('kernel.environment', 'test');
        $placeholder = $bag->get('env(HTTP_LOG_TABLE_PREFIX)');
        self::assertIsString($placeholder);

        BaseNode::setPlaceholderUniquePrefix($bag->getEnvPlaceholderUniquePrefix());
        try {
            (new NowoHttpLogExtension())->load([['security' => ['allow_unauthenticated' => true], 'doctrine' => ['table_prefix' => $placeholder]]], $container);
        } finally {
            BaseNode::resetPlaceholders();
        }
    }

    #[Test]
    public function listenerRejectsInvalidResolvedValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the limit is 63');

        new TablePrefixListener(str_repeat('a', 40));
    }

    #[Test]
    public function listenerRejectsMalformedPrefixes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid doctrine.table_prefix');

        new TablePrefixListener('App_');
    }

    #[Test]
    public function listenerRejectsMalformedTableNamePrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid doctrine.table_name_prefix');

        new TablePrefixListener('', '_log');
    }

    #[Test]
    public function eraserIsAPublicService(): void
    {
        $container = $this->loadDoctrine([]);

        self::assertTrue($container->getAlias(HttpLogPersonalDataEraserInterface::class)->isPublic());
        self::assertSame(HttpLogPersonalDataEraser::class, (string) $container->getAlias(HttpLogPersonalDataEraserInterface::class));
        self::assertSame('%nowo_http_log.retention%', $container->getDefinition(HttpLogPersonalDataEraser::class)->getArgument('$retentionConfig'));
    }

    /**
     * @param array<string, mixed> $doctrine
     */
    private function loadDoctrine(array $doctrine): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        (new NowoHttpLogExtension())->load([['security' => ['allow_unauthenticated' => true], 'doctrine' => $doctrine]], $container);

        return $container;
    }

    #[Test]
    public function tableNamePrefixMustNotBeEmpty(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('use a lowercase letter, then lowercase letters');

        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        (new NowoHttpLogExtension())->load([['doctrine' => ['table_name_prefix' => '']]], $container);
    }

    #[Test]
    public function tablePrefixListenerIsRegisteredWithPrefix(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        (new NowoHttpLogExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'doctrine' => ['table_prefix' => 'app_'],
        ]], $container);

        self::assertSame('app_', $container->getParameter('nowo_http_log.doctrine.table_prefix'));
        $definition = $container->getDefinition(TablePrefixListener::class);
        self::assertSame('%nowo_http_log.doctrine.table_prefix%', $definition->getArgument('$tablePrefix'));
        self::assertSame([['event' => 'loadClassMetadata']], $definition->getTag('doctrine.event_listener'));
    }

    #[Test]
    public function tablePrefixRejectsInvalidCharacters(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('use a lowercase letter, then lowercase letters');

        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        (new NowoHttpLogExtension())->load([['doctrine' => ['table_prefix' => 'app-']]], $container);
    }

    #[Test]
    public function loadMergesKitDefaultIgnorePathPrefixesByDefault(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        (new NowoHttpLogExtension())->load([['security' => ['allow_unauthenticated' => true]]], $container);

        self::assertSame(
            ['/admin/http-log', '/_nowo/', '/sw.js', '/manifest.webmanifest', '/_wdt/', '/_profiler/'],
            $container->getParameter('nowo_http_log.ignore_path_prefixes'),
        );
    }

    #[Test]
    public function loadKeepsHostIgnorePathPrefixesAndAddsMissingKitDefaults(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        (new NowoHttpLogExtension())->load([[
            'security'             => ['allow_unauthenticated' => true],
            'ignore_path_prefixes' => ['/api', '/sw.js'],
        ]], $container);

        self::assertSame(
            ['/api', '/sw.js', '/_nowo/', '/manifest.webmanifest', '/_wdt/', '/_profiler/'],
            $container->getParameter('nowo_http_log.ignore_path_prefixes'),
        );
    }

    #[Test]
    public function loadUsesOnlyHostIgnorePathPrefixesWhenKitDefaultsDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');

        (new NowoHttpLogExtension())->load([[
            'security'                     => ['allow_unauthenticated' => true],
            'ignore_path_prefixes'         => ['/api'],
            'ignore_default_path_prefixes' => false,
        ]], $container);

        self::assertSame(['/api'], $container->getParameter('nowo_http_log.ignore_path_prefixes'));
    }

    #[Test]
    public function loadExposesEscapedAdminChromeParameter(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.project_dir', '/app');

        (new NowoHttpLogExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'web_ui'   => [
                'css_classes'        => ['table' => 'w-[50%]'],
                'confirm_attributes' => ['data-confirm-submit-message-value' => '{message}'],
                'ui_kit_stylesheet'  => false,
            ],
        ]], $container);

        $container->getParameterBag()->resolve();
        /** @var array<string, mixed> $chrome */
        $chrome = $container->getParameter('nowo_http_log.web_ui.chrome');

        self::assertSame('w-[50%]', $chrome['css_classes']['table']);
        self::assertSame('{message}', $chrome['confirm_attributes']['data-confirm-submit-message-value']);
        self::assertSame('/app/var/http-log/exports', $container->getParameter('nowo_http_log.export.directory'));
        self::assertSame(86400, $container->getParameter('nowo_http_log.export.ttl_seconds'));
        self::assertFalse($chrome['ui_kit_stylesheet']);
        self::assertSame(AdminChrome::DEFAULT_TEMPLATES, $chrome['templates']);
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
                    'nowo_http_log_path_prefix' => '/http-log',
                    'nowo_http_log_page_size'   => 75,
                ],
            ],
        ], $container->getExtensionConfig('twig'));
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
        self::assertTrue($merged['profiles']['http_log']['convention_requires_translation']);
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
        self::assertArrayNotHasKey('icon_set', $merged, 'icon_set is seeded only when set in nowo_http_log.web_ui');
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
    public function prependSeedsNothingTheHostDidNotSetInWebUi(): void
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
        self::assertSame([['css_framework' => 'bootstrap5']], $configs, 'defaults are inherited from nowo_ui_kit (same values)');
    }

    #[Test]
    public function prependBridgesTheDeprecatedChromeIntoTheUiKitBundleTheme(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('nowo_ui_kit'));
        $container->registerExtension(new NowoHttpLogExtension());
        $container->prependExtensionConfig('nowo_ui_kit', ['css_framework' => 'bootstrap5', 'icon_set' => 'bootstrap-icons']);
        $container->loadFromExtension('nowo_http_log', [
            'security' => ['allow_unauthenticated' => true],
            'web_ui'   => [
                'css_classes' => ['table' => 'table table-sm table-dark', 'filter' => 'nowo-ui-filter card mb-4'],
                'attributes'  => ['table' => ['data-testid' => 'http-log-table']],
                'templates'   => ['pagination' => 'kit/_pagination.html.twig'],
            ],
        ]);

        (new NowoHttpLogExtension())->prepend($container);

        $configs = $container->getExtensionConfig('nowo_ui_kit');
        self::assertCount(2, $configs);
        self::assertSame([
            'theme' => [
                'bundles' => [
                    'NowoHttpLogBundle' => [
                        'classes'    => ['table' => 'table table-sm table-dark'],
                        'attributes' => ['table' => ['data-testid' => 'http-log-table']],
                        'partials'   => ['pagination' => 'kit/_pagination.html.twig'],
                    ],
                ],
            ],
        ], $configs[0], 'prepended before the host config, so the host theme still wins; default values bridge nothing');
    }

    #[Test]
    public function prependSeedsUiKitIconSetFromWebUi(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->createExtension('nowo_ui_kit'));
        $container->registerExtension(new NowoHttpLogExtension());
        $container->loadFromExtension('nowo_http_log', [
            'security' => ['allow_unauthenticated' => true],
            'web_ui'   => ['css_framework' => 'tailwind', 'icon_set' => 'tabler-icons'],
        ]);

        (new NowoHttpLogExtension())->prepend($container);

        $merged = array_replace_recursive(...array_reverse($container->getExtensionConfig('nowo_ui_kit')));
        self::assertSame('tailwind', $merged['css_framework']);
        self::assertSame('tabler-icons', $merged['icon_set']);
    }

    #[Test]
    public function loadExposesEnabledExportFormats(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        (new NowoHttpLogExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'export'   => ['formats' => ['json', 'json']],
        ]], $container);

        self::assertSame(['json'], $container->getParameter('nowo_http_log.export.formats'));
        self::assertSame('/admin/http-log', $container->getParameter('nowo_http_log.web_ui.path_prefix'));
    }

    #[Test]
    public function loadPassesWebUiPathPrefixToSubscriber(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        (new NowoHttpLogExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'web_ui'   => ['path_prefix' => '/ops/logs/'],
        ]], $container);

        self::assertSame('/ops/logs', $container->getDefinition(HttpLogSubscriber::class)->getArgument('$webUiPathPrefix'));
    }

    #[Test]
    public function loadDoesNotIgnoreWebUiPathPrefixWhenWebUiDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        (new NowoHttpLogExtension())->load([[
            'security' => ['allow_unauthenticated' => true],
            'web_ui'   => ['enabled' => false, 'path_prefix' => '/ops/logs'],
        ]], $container);

        self::assertNull($container->getDefinition(HttpLogSubscriber::class)->getArgument('$webUiPathPrefix'));
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
