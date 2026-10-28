<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\DependencyInjection;

use LogicException;
use Nowo\HttpLogBundle\Controller\HttpLogAdminController;
use Nowo\HttpLogBundle\Doctrine\TablePrefixListener;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogSubscriber;
use Nowo\HttpLogBundle\Security\ConfigurableHttpLogAccessChecker;
use Nowo\HttpLogBundle\Security\HttpLogAccessCheckerInterface;
use Nowo\HttpLogBundle\View\AdminChrome;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

use function array_key_exists;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function is_array;
use function is_string;
use function str_replace;

/**
 * Loads bundle configuration and registers services.
 */
final class NowoHttpLogExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitDefaults($container);
        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig('framework', [
                'translator' => [
                    'paths'     => [__DIR__ . '/../Resources/translations'],
                    'fallbacks' => ['en'],
                ],
            ]);
        }

        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'NowoHttpLogBundle' => [
                            'type'      => 'attribute',
                            'is_bundle' => true,
                        ],
                    ],
                ],
            ]);
        }

        $configs = $container->getExtensionConfig($this->getAlias());
        if ($configs !== []) {
            $config = $this->processConfiguration(new Configuration(), $configs);

            if ($config['web_ui']['enabled'] && $container->hasExtension('twig')) {
                $container->prependExtensionConfig('twig', [
                    'globals' => [
                        'nowo_http_log_path_prefix' => $config['web_ui']['path_prefix'],
                        'nowo_http_log_page_size'   => $config['web_ui']['page_size'],
                    ],
                ]);
            }
        }

        $this->prependUiKitDefaults($container);
    }

    /**
     * When FormKit is installed, register the http_log profile. Forms select it via #[FormKitConfig].
     */
    private function prependFormKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasProfile      = false;
        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            /** @var array<string, mixed> $cfg */
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            $profiles = $cfg['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists('http_log', $profiles)) {
                $hostHasProfile = true;
            }
        }

        $seed = [];

        if (!$hostHasCssFramework) {
            $seed['css_framework'] = 'bootstrap';
        }

        if (!$hostHasProfile) {
            $seed['profiles'] = [
                'http_log' => [
                    'alias'              => 'http_log',
                    'translation_domain' => 'NowoHttpLogBundle',
                    // Filter fields have no {form}.{field}.help / .placeholder translations: only apply the
                    // convention keys when the host translates them, never render the raw keys.
                    'convention_requires_translation' => true,
                    // Rows sit in the UiKit filter row (filters_field slot): no spacing utilities on the rows.
                    'defaults' => [
                        'attr' => ['class' => 'nowo-ui-input form-control'],
                    ],
                    'field_types' => [
                        'checkbox' => [
                            'attr'     => ['class' => 'form-check-input'],
                            'row_attr' => ['class' => 'form-check'],
                        ],
                        'choice' => [
                            'attr' => ['class' => 'form-select'],
                        ],
                        'entity' => [
                            'attr' => ['class' => 'form-select'],
                        ],
                        'file' => [
                            'attr' => ['class' => 'nowo-ui-input form-control'],
                        ],
                        'textarea' => [
                            'attr' => ['class' => 'nowo-ui-input form-control'],
                        ],
                    ],
                ],
            ];
        }

        if ($seed !== []) {
            $container->prependExtensionConfig('nowo_form_kit', $seed);
        }
    }

    /**
     * When UiKit is installed:
     * - seed nowo_ui_kit.css_framework / icon_set from the deprecated web_ui keys, only when the host set them here
     *   and not in nowo_ui_kit (the defaults of both bundles are the same: bootstrap5 / bootstrap-icons);
     * - bridge the deprecated 1.x chrome keys into nowo_ui_kit.theme.bundles.NowoHttpLogBundle (prepended, so the
     *   host's own nowo_ui_kit config still wins).
     */
    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasIconSet      = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            if (array_key_exists('icon_set', $cfg)) {
                $hostHasIconSet = true;
            }
        }

        $rawConfigs = $container->getExtensionConfig(Configuration::ALIAS);
        $setHere    = static function (string $key) use ($rawConfigs): bool {
            foreach ($rawConfigs as $cfg) {
                if (is_array($cfg['web_ui'] ?? null) && array_key_exists($key, $cfg['web_ui'])) {
                    return true;
                }
            }

            return false;
        };

        $config = $this->processConfiguration(new Configuration(), $rawConfigs);
        /** @var array<string, mixed> $webUi */
        $webUi    = $config['web_ui'];
        $defaults = [];

        if (!$hostHasCssFramework && $setHere('css_framework')) {
            $defaults['css_framework'] = (string) $webUi['css_framework'];
        }
        if (!$hostHasIconSet && $setHere('icon_set')) {
            $defaults['icon_set'] = (string) $webUi['icon_set'];
        }

        $theme = LegacyThemeBridge::themeException($webUi);
        if ($theme !== []) {
            $defaults['theme'] = ['bundles' => [LegacyThemeBridge::BUNDLE => $theme]];
        }

        if ($defaults !== []) {
            $container->prependExtensionConfig('nowo_ui_kit', $defaults);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $config = $this->processConfiguration(new Configuration(), $configs);

        if (
            $config['web_ui']['enabled']
            && !$config['security']['allow_unauthenticated']
            && !$this->isSecurityBundleAvailable($container)
        ) {
            throw new LogicException('NowoHttpLogBundle web UI requires symfony/security-bundle when security.allow_unauthenticated is false.');
        }

        $alias = Configuration::ALIAS;
        $container->setParameter($alias . '.enabled', $config['enabled']);
        $container->setParameter($alias . '.environments', $config['environments']);
        $container->setParameter($alias . '.async', $config['async']);
        $container->setParameter($alias . '.sampling_rate', $config['sampling_rate']);
        $container->setParameter($alias . '.track_sub_requests', $config['track_sub_requests']);
        $container->setParameter($alias . '.ignore_routes', $config['ignore_routes']);
        $container->setParameter($alias . '.ignore_path_prefixes', $this->resolveIgnorePathPrefixes($config['ignore_path_prefixes'], $config['ignore_default_path_prefixes']));
        $container->setParameter($alias . '.ignore_unrouted', $config['ignore_unrouted']);
        $container->setParameter($alias . '.capture', $config['capture']);
        $container->setParameter($alias . '.redaction', $config['redaction']);
        $container->setParameter($alias . '.retention', $config['retention']);
        $container->setParameter($alias . '.export', $config['export']);
        $container->setParameter($alias . '.export.max_sync_rows', $config['export']['max_sync_rows']);
        $container->setParameter($alias . '.export.formats', array_values(array_unique($config['export']['formats'])));
        $container->setParameter($alias . '.export.directory', $config['export']['directory']);
        $container->setParameter($alias . '.export.ttl_seconds', $config['export']['ttl_hours'] * 3600);
        $container->setParameter($alias . '.redaction.headers', $config['redaction']['headers']);
        $container->setParameter($alias . '.redaction.query_params', $config['redaction']['query_params']);
        $container->setParameter($alias . '.redaction.json_paths', $config['redaction']['json_paths']);
        $container->setParameter($alias . '.web_ui', self::escapeParameterValue($config['web_ui']));
        $container->setParameter($alias . '.web_ui.enabled', $config['web_ui']['enabled']);
        $container->setParameter($alias . '.web_ui.path_prefix', $config['web_ui']['path_prefix']);
        $container->setParameter($alias . '.web_ui.page_size', $config['web_ui']['page_size']);
        $container->setParameter($alias . '.web_ui.chrome', self::escapeParameterValue(AdminChrome::fromWebUi($config['web_ui'])));
        $container->setParameter($alias . '.security', $config['security']);
        $container->setParameter($alias . '.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);

        // The admin UI never records its own requests, even with a custom web_ui.path_prefix.
        $container->getDefinition(HttpLogSubscriber::class)
            ->setArgument('$webUiPathPrefix', $config['web_ui']['enabled'] ? $config['web_ui']['path_prefix'] : null);

        if (!$config['web_ui']['enabled'] && $container->hasDefinition(HttpLogAdminController::class)) {
            $container->removeDefinition(HttpLogAdminController::class);
        }

        // REQ-ORM-002: the table prefix listener only exists when a prefix is configured.
        $container->setParameter($alias . '.doctrine.table_prefix', $config['doctrine']['table_prefix']);
        if ($config['doctrine']['table_prefix'] === '') {
            $container->removeDefinition(TablePrefixListener::class);
        }

        $this->registerAccessChecker($container, $config['security']);
    }

    /**
     * Escape "%" so container parameter resolution keeps literal values (e.g. "w-[50%]").
     */
    /**
     * Host list first, then the kit defaults it does not already contain (`ignore_default_path_prefixes`).
     *
     * @param list<string> $prefixes
     *
     * @return list<string>
     */
    private function resolveIgnorePathPrefixes(array $prefixes, bool $withKitDefaults): array
    {
        if ($withKitDefaults) {
            $prefixes = array_merge($prefixes, Configuration::DEFAULT_IGNORE_PATH_PREFIXES);
        }

        return array_values(array_unique($prefixes));
    }

    private static function escapeParameterValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace('%', '%%', $value);
        }

        if (is_array($value)) {
            return array_map(self::escapeParameterValue(...), $value);
        }

        return $value;
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /** @param array<string, mixed> $security */
    private function registerAccessChecker(ContainerBuilder $container, array $security): void
    {
        $accessCheckerId = $security['access_checker'] ?? null;
        if (!is_string($accessCheckerId) || $accessCheckerId === '') {
            $accessCheckerId = 'nowo_http_log.access_checker.default';
            $container->setDefinition($accessCheckerId, (new Definition(ConfigurableHttpLogAccessChecker::class))
                ->setAutowired(true)
                ->setArgument('$accessRoles', $security['access_roles']));
        }

        $container->setAlias(HttpLogAccessCheckerInterface::class, $accessCheckerId);
    }

    /**
     * Prefer kernel.bundles: ContainerBuilder::hasExtension() can be false while SecurityBundle
     * is already registered (e.g. during early Flex cache:clear boots).
     */
    private function isSecurityBundleAvailable(ContainerBuilder $container): bool
    {
        if ($container->hasExtension('security')) {
            return true;
        }

        if (!$container->hasParameter('kernel.bundles')) {
            return false;
        }

        /** @var array<string, class-string> $bundles */
        $bundles = $container->getParameter('kernel.bundles');

        return isset($bundles['SecurityBundle']);
    }
}
