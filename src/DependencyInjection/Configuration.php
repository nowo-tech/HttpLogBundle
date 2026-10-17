<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\DependencyInjection;

use Nowo\HttpLogBundle\Enum\CssFramework;
use Nowo\HttpLogBundle\Enum\ExportFormat;
use Nowo\HttpLogBundle\View\AdminChrome;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_diff;
use function array_keys;
use function array_map;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function rtrim;
use function str_contains;
use function str_starts_with;

/**
 * Configuration tree for {@see NowoHttpLogExtension}.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_http_log';

    /**
     * Kit-internal paths merged into `ignore_path_prefixes` while `ignore_default_path_prefixes` is true:
     * HotReloadBundle client / shared worker (`/_nowo/`), PwaBundle service worker and manifest.
     *
     * @var list<string>
     */
    public const DEFAULT_IGNORE_PATH_PREFIXES = ['/_nowo/', '/sw.js', '/manifest.webmanifest'];

    public const DEFAULT_PURGE_BATCH_SIZE = 1000;

    /** Composer package name used in configuration deprecations. */
    public const PACKAGE = 'nowo-tech/http-log-bundle';

    /** The 1.x default `web_ui.layout_template` (removed CDN layout); normalized to null = UiKit layout. */
    public const LEGACY_LAYOUT_TEMPLATE = '@NowoHttpLogBundle/layout.html.twig';

    /** Pages the controller renders (`web_ui.templates.<page>`). */
    public const PAGE_TEMPLATES = [
        'index' => '@NowoHttpLogBundle/admin/index.html.twig',
        'show'  => '@NowoHttpLogBundle/admin/show.html.twig',
    ];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->arrayNode('environments')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->info('Empty list means all environments')
                ->end()
                ->booleanNode('async')->defaultTrue()->end()
                ->floatNode('sampling_rate')
                    ->defaultValue(1.0)
                    ->min(0.0)
                    ->max(1.0)
                ->end()
                ->booleanNode('track_sub_requests')->defaultFalse()->end()
                ->arrayNode('ignore_routes')
                    ->scalarPrototype()->end()
                    ->defaultValue(['_wdt', '_profiler', 'web_profiler*', 'nowo_http_log_*'])
                ->end()
                ->arrayNode('ignore_path_prefixes')
                    ->scalarPrototype()->end()
                    ->defaultValue(['/admin/http-log'])
                ->end()
                ->booleanNode('ignore_default_path_prefixes')
                    ->info('Also skip the kit-internal paths /_nowo/ (HotReload), /sw.js and /manifest.webmanifest (PWA), merged with ignore_path_prefixes.')
                    ->defaultTrue()
                ->end()
                ->booleanNode('ignore_unrouted')
                    ->info('Skip requests no route matched (unrouted 404s: scanners probing /wp-login.php, /.env…).')
                    ->defaultFalse()
                ->end()
                ->arrayNode('capture')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('request_headers')->defaultTrue()->end()
                        ->booleanNode('request_body')->defaultFalse()->end()
                        ->booleanNode('response_headers')->defaultTrue()->end()
                        ->booleanNode('client_ip')->defaultTrue()->end()
                        ->booleanNode('user')->defaultTrue()->end()
                        ->integerNode('max_body_bytes')
                            ->defaultValue(65536)
                            ->min(0)
                        ->end()
                        ->arrayNode('response_body_by_type')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('html')->defaultFalse()->end()
                                ->booleanNode('json')->defaultTrue()->end()
                                ->booleanNode('soap')->defaultTrue()->end()
                                ->booleanNode('xml')->defaultTrue()->end()
                                ->booleanNode('text')->defaultFalse()->end()
                                ->booleanNode('binary')->defaultFalse()->end()
                                ->booleanNode('other')->defaultFalse()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('redaction')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('headers')
                            ->scalarPrototype()->end()
                            ->defaultValue(['Authorization', 'Cookie', 'Set-Cookie', 'X-Api-Key'])
                        ->end()
                        ->arrayNode('query_params')
                            ->scalarPrototype()->end()
                            ->defaultValue(['password', '_password', 'token', '_token', '_csrf_token', 'api_key'])
                        ->end()
                        ->arrayNode('json_paths')
                            ->scalarPrototype()->end()
                            ->defaultValue(['*.password', '*._password', '*.token', '*._token', '*._csrf_token'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('retention')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->variableNode('days')
                            ->defaultValue(30)
                            ->info('Null keeps entries forever')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_int($v) || $v < 0))
                                ->thenInvalid('Retention days must be a non-negative integer or null')
                            ->end()
                        ->end()
                        ->integerNode('purge_batch_size')
                            ->info('Rows deleted per statement by the purge (keyset batches by id), so a large log does not lock the table or flood the binlog.')
                            ->defaultValue(self::DEFAULT_PURGE_BATCH_SIZE)
                            ->min(1)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('export')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('formats')
                            ->info('Export formats allowed in the admin UI and the nowo:http-log:export command.')
                            ->enumPrototype()
                                ->values(array_map(static fn (ExportFormat $f): string => $f->value, ExportFormat::cases()))
                            ->end()
                            ->requiresAtLeastOneElement()
                            ->defaultValue(['csv', 'json'])
                        ->end()
                        ->integerNode('max_sync_rows')->defaultValue(1000)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->beforeNormalization()
                        // The 1.x default layout (Bootstrap from a CDN) is gone: that value now means the UiKit layout.
                        ->ifTrue(static fn (mixed $v): bool => is_array($v) && ($v['layout_template'] ?? null) === self::LEGACY_LAYOUT_TEMPLATE)
                        ->then(static function (array $v): array {
                            $v['layout_template'] = null;

                            return $v;
                        })
                    ->end()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('path_prefix')
                            ->info('Prefix of the admin UI routes (list, detail, export, purge, delete).')
                            ->defaultValue('/admin/http-log')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || !str_starts_with($v, '/') || rtrim($v, '/') === '' || str_contains($v, '%'))
                                ->thenInvalid('web_ui.path_prefix must start with "/", must not be "/" alone and must not contain "%%"; got %s')
                            ->end()
                            ->validate()
                                ->always(static fn (string $v): string => rtrim($v, '/'))
                            ->end()
                        ->end()
                        ->scalarNode('icon_set')
                            ->info('Deprecated: set nowo_ui_kit.icon_set. When set here (and not in nowo_ui_kit) it is still seeded into UiKit.')
                            ->setDeprecated(self::PACKAGE, '1.8', 'The "%path%.%node%" option is deprecated, set "nowo_ui_kit.icon_set" instead.')
                            ->defaultValue('bootstrap-icons')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('layout_template')
                            ->info('Layout the admin pages extend. null (default) = the UiKit admin layout (nowo_ui_kit.layouts.admin).')
                            ->defaultNull()
                        ->end()
                        ->enumNode('css_framework')
                            ->info('Deprecated: set nowo_ui_kit.css_framework. When set here (and not in nowo_ui_kit) it is still seeded into UiKit.')
                            ->setDeprecated(self::PACKAGE, '1.8', 'The "%path%.%node%" option is deprecated, set "nowo_ui_kit.css_framework" instead.')
                            ->values(array_map(static fn (CssFramework $f): string => $f->value, CssFramework::cases()))
                            ->defaultValue(CssFramework::Bootstrap5->value)
                        ->end()
                        ->integerNode('page_size')
                            ->defaultValue(50)
                            ->min(1)
                        ->end()
                        ->append($this->templatesNode())
                        ->append($this->cssClassesNode())
                        ->arrayNode('attributes')
                            ->info('Deprecated: extra HTML attributes per 1.x chrome element, bridged into nowo_ui_kit.theme.bundles.NowoHttpLogBundle.attributes (see UPGRADING).')
                            ->setDeprecated(self::PACKAGE, '1.8', 'The "%path%.%node%" option is deprecated, use "nowo_ui_kit.theme.bundles.NowoHttpLogBundle.attributes" instead.')
                            ->useAttributeAsKey('element')
                            ->normalizeKeys(false)
                            ->arrayPrototype()
                                ->useAttributeAsKey('name')
                                ->normalizeKeys(false)
                                ->scalarPrototype()->end()
                            ->end()
                            ->defaultValue([])
                            ->validate()
                                ->ifTrue(static fn (array $v): bool => array_diff(array_keys($v), AdminChrome::ATTRIBUTE_TARGETS) !== [])
                                ->thenInvalid('web_ui.attributes keys must be one of: ' . implode(', ', AdminChrome::ATTRIBUTE_TARGETS) . '; got %s')
                            ->end()
                        ->end()
                        ->booleanNode('ui_kit_stylesheet')
                            ->info('Deprecated, no effect: the UiKit layout (or the host layout) loads nowo-ui.css.')
                            ->setDeprecated(self::PACKAGE, '1.8', 'The "%path%.%node%" option is deprecated and has no effect: the admin layout loads nowo-ui.css.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('header_actions_in_layout')
                            ->info('Deprecated, no effect: header actions are part of the page header the layout renders (nowo_ui_page_header).')
                            ->setDeprecated(self::PACKAGE, '1.8', 'The "%path%.%node%" option is deprecated and has no effect: header actions render in the page header (nowo_ui_page_header block).')
                            ->defaultFalse()
                        ->end()
                        ->arrayNode('confirm_attributes')
                            ->info('Deprecated, no effect: purge / delete open the UiKit confirm dialog (no inline confirm()).')
                            ->setDeprecated(self::PACKAGE, '1.8', 'The "%path%.%node%" option is deprecated and has no effect: purge and delete use the UiKit confirm dialog.')
                            ->useAttributeAsKey('name')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->booleanNode('ui_kit_row_actions')
                            ->info('Deprecated, no effect: row actions always render through the UiKit row_actions partial (nowo_ui_kit.row_actions_display).')
                            ->setDeprecated(self::PACKAGE, '1.8', 'The "%path%.%node%" option is deprecated and has no effect: row actions always use the UiKit row_actions partial.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('access_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                        ->end()
                        ->scalarNode('access_checker')->defaultNull()->end()
                        ->booleanNode('allow_unauthenticated')->defaultFalse()->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    private function templatesNode(): ArrayNodeDefinition
    {
        $node     = (new TreeBuilder('templates'))->getRootNode();
        $children = $node
            ->info('Twig templates of the admin pages (index, show). filter / pagination are deprecated (UiKit theme partials).')
            ->addDefaultsIfNotSet()
            ->children();

        foreach (self::PAGE_TEMPLATES as $key => $default) {
            $children->scalarNode($key)->defaultValue($default)->cannotBeEmpty()->end();
        }

        $children->scalarNode('filter')
            ->info('Deprecated: bridged to nowo_ui_kit.theme.bundles.NowoHttpLogBundle.partials.list_filters.')
            ->setDeprecated(self::PACKAGE, '1.8', 'The "%path%.%node%" option is deprecated, use "nowo_ui_kit.theme.bundles.NowoHttpLogBundle.partials.list_filters" instead.')
            ->defaultNull()
        ->end();
        $children->scalarNode('pagination')
            ->info('Deprecated: bridged to nowo_ui_kit.theme.bundles.NowoHttpLogBundle.partials.pagination.')
            ->setDeprecated(self::PACKAGE, '1.8', 'The "%path%.%node%" option is deprecated, use "nowo_ui_kit.theme.bundles.NowoHttpLogBundle.partials.pagination" instead.')
            ->defaultNull()
        ->end();

        return $node;
    }

    private function cssClassesNode(): ArrayNodeDefinition
    {
        $node     = (new TreeBuilder('css_classes'))->getRootNode();
        $children = $node
            ->info('Deprecated: 1.x class map, bridged into nowo_ui_kit.theme.bundles.NowoHttpLogBundle.classes (see UPGRADING).')
            ->setDeprecated(self::PACKAGE, '1.8', 'The "%path%.%node%" option is deprecated, use "nowo_ui_kit.theme.bundles.NowoHttpLogBundle.classes" instead.')
            ->addDefaultsIfNotSet()
            ->children();

        foreach (AdminChrome::DEFAULT_CSS_CLASSES as $key => $default) {
            $children->scalarNode($key)->defaultValue($default)->end();
        }

        return $node;
    }
}
