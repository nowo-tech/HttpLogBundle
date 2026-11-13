<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\DependencyInjection;

use Nowo\HttpLogBundle\Doctrine\TablePrefixListener;
use Nowo\HttpLogBundle\Enum\CssFramework;
use Nowo\HttpLogBundle\Enum\ExportFormat;
use Nowo\HttpLogBundle\Inbound\HttpRequestContextFactory;
use Nowo\HttpLogBundle\View\AdminChrome;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function array_unique;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr_count;

/**
 * Configuration tree for {@see NowoHttpLogExtension}.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_http_log';

    /**
     * Kit-internal paths merged into `ignore_path_prefixes` while `ignore_default_path_prefixes` is true:
     * HotReloadBundle client / shared worker (`/_nowo/`), PwaBundle service worker and manifest, and the Symfony
     * WebProfiler toolbar / profiler (`/_wdt/`, `/_profiler/`, since 1.9).
     *
     * @var list<string>
     */
    public const DEFAULT_IGNORE_PATH_PREFIXES = ['/_nowo/', '/sw.js', '/manifest.webmanifest', '/_wdt/', '/_profiler/'];

    public const DEFAULT_PURGE_BATCH_SIZE = 1000;

    /** Entries exported at most per subject by the personal-data export (newest first). */
    public const DEFAULT_PERSONAL_DATA_EXPORT_LIMIT = 1000;

    /** Composer package name used in configuration deprecations. */
    public const PACKAGE = 'nowo-tech/http-log-bundle';

    /** The 1.x default `web_ui.layout_template` (removed CDN layout); normalized to null = UiKit layout. */
    public const LEGACY_LAYOUT_TEMPLATE = '@NowoHttpLogBundle/layout.html.twig';

    /** `web_ui.filter_match`: text filters match anywhere in the value (1.16 behaviour, full scan). */
    public const FILTER_MATCH_CONTAINS = 'contains';

    /** `web_ui.filter_match`: text filters match the start of the value, through indexes (PERF-1147-02). */
    public const FILTER_MATCH_PREFIX = 'prefix';

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
                    ->info('Also skip the kit-internal paths /_nowo/ (HotReload), /sw.js and /manifest.webmanifest (PWA) and the WebProfiler paths /_wdt/ and /_profiler/, merged with ignore_path_prefixes.')
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
                        ->arrayNode('credential_headers')
                            ->info('Headers that carry the request credential besides Authorization (e.g. X-Api-Key when your API authenticates with it). The named-channel resolver guard drops a value that contains or starts them; every other redacted value only when equal. Default: Authorization only.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('context_headers')
                            ->info('Headers a framework-free request-context resolver (HttpRequestContextResolverInterface) may read: an allowlist. Add your tenant header (e.g. X-Tenant). Authorization, Proxy-Authorization, Cookie, Referer, Forwarded, X-Original-URL, X-Rewrite-URL, the credential and redacted headers, and any name containing token, secret, key, auth, session, signature, passw or cookie are never given, even when listed.')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue(HttpRequestContextFactory::DEFAULT_CONTEXT_HEADERS)
                        ->end()
                        ->arrayNode('context_attributes')
                            ->info('Request attributes (exact names) a framework-free request-context resolver receives with their raw value, objects included, through HttpRequestContext::allowlistedAttribute() (e.g. the credential object your authenticator stamps on the request). Default: none. Your explicit list wins over the secret-name and "_" prefix rules: name only what your resolvers need. The bundle never logs or persists these values. Since 1.20.0.')
                            ->scalarPrototype()
                                ->cannotBeEmpty()
                                ->validate()
                                    ->ifTrue(static fn (mixed $value): bool => !is_string($value))
                                    ->thenInvalid('nowo_http_log.redaction.context_attributes entries must be attribute names (strings), got %s.')
                                ->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('json_paths')
                            ->scalarPrototype()->end()
                            ->defaultValue(['*.password', '*._password', '*.token', '*._token', '*._csrf_token', '*.value'])
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
                ->arrayNode('personal_data')
                    ->info('GDPR erasure and export (HttpLogPersonalDataErasureInterface / HttpLogPersonalDataExporterInterface) and the UserKit bridge.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Opt-in (owner decision LY-L1): with nowo-tech/user-kit-bundle installed, register the "http_log" eraser and the "http_requests" export section. Keep false when you must retain access logs (GDPR art. 17(3)) or already erase this table.')
                            ->defaultFalse()
                        ->end()
                        ->integerNode('export_limit')
                            ->info('Entries exported at most per subject, newest first (the export also reports the total).')
                            ->defaultValue(self::DEFAULT_PERSONAL_DATA_EXPORT_LIMIT)
                            ->min(1)
                            ->max(100000)
                        ->end()
                        ->scalarNode('subject_resolver')
                            ->info('Service id implementing HttpLogPersonalDataSubjectResolverInterface (default: the user identifier).')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => $value !== null && (!is_string($value) || $value === ''))
                                ->thenInvalid('Invalid "nowo_http_log.personal_data.subject_resolver" %s: use a service id or null.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->info('Doctrine mapping of the bundle-owned table.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('table_name_prefix')
                            ->defaultValue(TablePrefixListener::DEFAULT_TABLE_NAME_PREFIX)
                            ->info('Replaces the "nowo_http_log_" part of the table name (e.g. "http_log_" yields http_log_entry). The idx_http_log_* index names do not change.')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match(TablePrefixListener::PREFIX_PATTERN, $value) !== 1)
                                ->thenInvalid('Invalid "nowo_http_log.doctrine.table_name_prefix" %s: use a lowercase letter, then lowercase letters, digits and underscores.')
                            ->end()
                        ->end()
                        ->scalarNode('table_prefix')
                            ->defaultValue('')
                            ->info('Prefix prepended to the table and index names after table_name_prefix is applied (e.g. "app_" yields app_nowo_http_log_entry and app_idx_http_log_created_at). Empty: unprefixed.')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || ($value !== '' && preg_match(TablePrefixListener::PREFIX_PATTERN, $value) !== 1))
                                ->thenInvalid('Invalid "nowo_http_log.doctrine.table_prefix" %s: use a lowercase letter, then lowercase letters, digits and underscores (or empty).')
                            ->end()
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
                        ->scalarNode('directory')
                            ->info('Private directory of background (Messenger) exports: created 0700, files 0600, downloaded from the admin list by users allowed to export.')
                            ->defaultValue('%kernel.project_dir%/var/http-log/exports')
                            ->cannotBeEmpty()
                        ->end()
                        ->integerNode('ttl_hours')
                            ->info('Background exports are deleted this many hours after they finish.')
                            ->defaultValue(24)
                            ->min(1)
                        ->end()
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
                        ->enumNode('filter_match')
                            ->info('Text filters of the list (q, path, routeName): "contains" (LIKE \'%value%\', a scan of the table) or "prefix" (LIKE \'value%\' on indexes; maps the path_prefix column, see UPGRADING 1.17).')
                            ->values([self::FILTER_MATCH_CONTAINS, self::FILTER_MATCH_PREFIX])
                            ->defaultValue(self::FILTER_MATCH_CONTAINS)
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
                ->append($this->channelsNode())
                ->append($this->outboundNode())
                ->append($this->mailerNode())
                ->append($this->rollupNode())
                ->arrayNode('messenger')
                    ->info('Context propagation for outbound and mailer rows (HL-1 §6.4).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('middleware')
                            ->info('Register the service nowo_http_log.messenger.context_middleware (add it to your buses).')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('propagate_tenant')
                            ->info('Copy the tenant / project in scope into the HttpLogContextStamp on dispatch.')
                            ->defaultTrue()
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

    /** Provider and channel keys (HL-1 §2). */
    public const PROVIDER_KEY_PATTERN = '/^[a-z][a-z0-9_.-]{0,63}\z/';

    /** Exact host, or one leading wildcard label, optional port; lower-case; no scheme, path or port wildcard. */
    public const HOST_PATTERN = '/^(?:\*\.)?(?:[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?)(?:\.[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?)*(?::[0-9]{1,5})?\z|^\[[0-9a-f:.]{2,45}\](?::[0-9]{1,5})?\z/';

    /** Literal segments and `{name}` placeholders. */
    public const PATH_TEMPLATE_PATTERN = '/^(?:\/(?:[A-Za-z0-9._~!$&\'()*+,;=:@-]+|\{[A-Za-z_][A-Za-z0-9_]{0,31}\}))+\z/';

    /** Mailer transport scheme (`smtp`, `resend+api`, …). */
    public const SCHEME_PATTERN = '/^[a-z][a-z0-9+.-]{0,15}\z/';

    /** Segments kept at most by a path template or a path depth. */
    public const MAX_PATH_SEGMENTS = 16;

    /** Named inbound channel (HL-1 §2): the `channel` column is 16 characters. */
    public const CHANNEL_NAME_PATTERN = '/^[a-z][a-z0-9_]{0,15}\z/';

    /** Names a named channel cannot take: the built-in channels and the purge scopes of the rollup. */
    public const RESERVED_CHANNEL_NAMES = ['web', 'outbound', 'mailer', 'rollup', 'ledger'];

    /** Client IP modes of a named channel (Q-O8). */
    public const CLIENT_IP_MODES = ['full', 'truncated', 'none'];

    /**
     * Named inbound channels (HL-1 §2, §6): matched before the web ignores, with their own sampling, retention and
     * allowlisted row (route path template, never the raw path, query string, headers or bodies).
     */
    private function channelsNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('channels'))->getRootNode();
        $node
            ->info('Named inbound channels (e.g. api, webhooks), matched before the web ignores; name ^[a-z][a-z0-9_]{0,15}$, not web, outbound, mailer, rollup or ledger.')
            ->useAttributeAsKey('name')
            ->normalizeKeys(false)
            ->validate()
                ->ifTrue(static fn (array $channels): bool => array_filter(array_keys($channels), static fn (int|string $key): bool => !is_string($key) || preg_match(self::CHANNEL_NAME_PATTERN, $key) !== 1 || in_array($key, self::RESERVED_CHANNEL_NAMES, true)) !== [])
                ->thenInvalid('nowo_http_log.channels keys must match ' . self::CHANNEL_NAME_PATTERN . ' and must not be one of ' . implode(', ', self::RESERVED_CHANNEL_NAMES) . '; got %s')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $channels): bool => self::hasSharedPrefix($channels))
                ->thenInvalid('Two nowo_http_log.channels have the same path prefix: %s')
            ->end()
            ->arrayPrototype()
                ->children()
                    ->append($this->channelPrefixesNode('path_prefixes', 'Segment-aware: "/api" matches /api and /api/x, not /apix.'))
                    ->append($this->channelRoutesNode('routes', 'fnmatch patterns on _route, case-insensitive.'))
                    ->append($this->channelPrefixesNode('exclude_path_prefixes', 'Paths of the channel that are not recorded by it (they fall through to the web channel).'))
                    ->append($this->channelRoutesNode('exclude_routes', 'Routes of the channel that are not recorded by it (they fall through to the web channel).'))
                    ->floatNode('sampling_rate')
                        ->info('Successful calls kept (0.0..1.0); errors (see error_status_min) and exceptions are always recorded.')
                        ->defaultValue(1.0)
                        ->min(0.0)
                        ->max(1.0)
                    ->end()
                    ->integerNode('error_status_min')
                        ->info('A status >= this value (or an exception) is always recorded and counted as an error (Q-O7).')
                        ->defaultValue(400)
                        ->min(100)
                        ->max(999)
                    ->end()
                    ->append($this->retentionDaysNode())
                    ->enumNode('client_ip')
                        ->info('full, truncated (IPv4 /24, IPv6 /48) or none (Q-O8).')
                        ->values(self::CLIENT_IP_MODES)
                        ->defaultValue('truncated')
                    ->end()
                    ->booleanNode('user')
                        ->info('Store the user identifier (only when a firewall with security enabled handled the request).')
                        ->defaultTrue()
                    ->end()
                    ->booleanNode('rollup')
                        ->info('Counted in the daily rollup while rollup.enabled.')
                        ->defaultTrue()
                    ->end()
                ->end()
                ->validate()
                    ->ifTrue(static fn (array $c): bool => $c['path_prefixes'] === [] && $c['routes'] === [])
                    ->thenInvalid('A named channel needs "path_prefixes" or "routes": %s')
                ->end()
            ->end()
            ->defaultValue([]);

        return $node;
    }

    private function channelPrefixesNode(string $name, string $info): ArrayNodeDefinition
    {
        $node = (new TreeBuilder($name))->getRootNode();
        $node
            ->info($info)
            ->scalarPrototype()
                ->validate()
                    ->ifTrue(static fn (mixed $p): bool => !is_string($p) || !str_starts_with($p, '/') || str_contains($p, '%') || preg_match('/[\x00-\x20\x7F?#]/', $p) === 1)
                    ->thenInvalid('Invalid channel path prefix %s: start with "/"; no spaces, "%%", "?" or "#".')
                ->end()
                // segment-aware matching: "/api/" and "/api" are the same prefix
                ->validate()
                    ->always(static fn (string $p): string => $p === '/' ? '/' : rtrim($p, '/'))
                ->end()
            ->end()
            ->defaultValue([]);

        return $node;
    }

    private function channelRoutesNode(string $name, string $info): ArrayNodeDefinition
    {
        $node = (new TreeBuilder($name))->getRootNode();
        $node
            ->info($info)
            ->scalarPrototype()->cannotBeEmpty()->end()
            ->defaultValue([]);

        return $node;
    }

    /** @param array<array-key, mixed> $channels */
    private static function hasSharedPrefix(array $channels): bool
    {
        $seen = [];
        foreach ($channels as $channel) {
            $prefixes = is_array($channel) && is_array($channel['path_prefixes'] ?? null) ? $channel['path_prefixes'] : [];
            foreach (array_unique($prefixes) as $prefix) {
                if (isset($seen[$prefix])) {
                    return true;
                }
                $seen[$prefix] = true;
            }
        }

        return false;
    }

    /** Daily rollup (HL-1 §9): counters per UTC day, channel, provider, credential and tenant, counted exactly once. */
    private function rollupNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('rollup'))->getRootNode();
        $node
            ->info('Daily usage rollup (HL-1 §9): two DBAL tables (daily, event_seen) mapped only while enabled.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->variableNode('retention_months')
                    ->info('Calendar months kept, the current month included (13 keeps the same month last year); null keeps them forever.')
                    ->defaultValue(13)
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_int($v) || $v < 1))
                        ->thenInvalid('rollup.retention_months must be a positive integer or null')
                    ->end()
                ->end()
                ->integerNode('dedupe_days')
                    ->info('Days the exactly-once ledger keeps an event id; must exceed the longest Messenger retry delay (replay failed messages within it).')
                    ->defaultValue(7)
                    ->min(1)
                ->end()
                ->booleanNode('web')
                    ->info('Also count the web channel (Q-O6); off by default.')
                    ->defaultFalse()
                ->end()
            ->end();

        return $node;
    }

    private function outboundNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('outbound'))->getRootNode();
        $node
            ->info('Outbound HttpClient capture (HL-1 §5): one allowlisted row per logical call; never headers, bodies or query strings.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->arrayNode('clients')
                    ->info('Service ids tagged http_client.client to decorate; "*" = all (http_client and every scoped client).')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue(['*'])
                ->end()
                ->arrayNode('exclude_clients')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue([])
                ->end()
                ->floatNode('sampling_rate')
                    ->info('Successful calls kept (0.0..1.0); errors (status >= 400 or a transport error) are always recorded.')
                    ->defaultValue(1.0)
                    ->min(0.0)
                    ->max(1.0)
                ->end()
                ->append($this->retentionDaysNode())
                ->booleanNode('record_unmapped')
                    ->info('Calls to a host no provider matches are recorded as provider "other"; false drops them.')
                    ->defaultTrue()
                ->end()
                ->integerNode('default_path_depth')
                    ->info('Path segments kept for unmapped hosts and providers without a matching template; the rest becomes /{…}.')
                    ->defaultValue(2)
                    ->min(0)
                    ->max(self::MAX_PATH_SEGMENTS)
                ->end()
                ->arrayNode('path_words')
                    ->info('Extra path segments kept verbatim on every host (besides versions and the built-in REST words); any other word becomes {seg}.')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $w): bool => !is_string($w) || preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,31}\z/', $w) !== 1)
                            ->thenInvalid('Invalid outbound path word %s: a letter, then letters, digits, "_", "." or "-" (at most 32 characters).')
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('exclude_hosts')
                    ->info('Hosts never captured (exact, or one leading "*." label): list the Messenger transport and database hosts whose client is decorated.')
                    ->beforeNormalization()
                        ->ifArray()
                        ->then(static fn (array $hosts): array => array_map(static fn (mixed $h): mixed => is_string($h) ? strtolower($h) : $h, $hosts))
                    ->end()
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(static fn (mixed $h): bool => !is_string($h) || preg_match(self::HOST_PATTERN, $h) !== 1)
                            ->thenInvalid('Invalid outbound excluded host %s: a host name (one leading "*." label at most), optional port.')
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->integerNode('decoration_priority')
                    ->info('Decoration priority: -1024 keeps the logger outermost (outside RetryableHttpClient and tracing decorators).')
                    ->defaultValue(-1024)
                ->end()
                ->arrayNode('providers')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->validate()
                        ->ifTrue(static fn (array $providers): bool => array_filter(array_keys($providers), static fn (int|string $key): bool => !is_string($key) || preg_match(self::PROVIDER_KEY_PATTERN, $key) !== 1) !== [])
                        ->thenInvalid('outbound.providers keys must match ' . self::PROVIDER_KEY_PATTERN . '; got %s')
                    ->end()
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('clients')
                                ->info('Scoped client service ids of this provider (matched before hosts).')
                                ->scalarPrototype()->cannotBeEmpty()->end()
                                ->defaultValue([])
                            ->end()
                            ->arrayNode('hosts')
                                ->info('Exact host, or one leading wildcard label ("*.amazonaws.com"); optional port.')
                                ->beforeNormalization()
                                    ->ifArray()
                                    ->then(static fn (array $hosts): array => array_map(static fn (mixed $h): mixed => is_string($h) ? strtolower($h) : $h, $hosts))
                                ->end()
                                ->scalarPrototype()
                                    ->validate()
                                        ->ifTrue(static fn (mixed $h): bool => !is_string($h) || preg_match(self::HOST_PATTERN, $h) !== 1)
                                        ->thenInvalid('Invalid outbound provider host %s: a host name (one leading "*." label at most), optional port; no scheme, path or port wildcard.')
                                    ->end()
                                ->end()
                                ->defaultValue([])
                            ->end()
                            ->arrayNode('path_templates')
                                ->info('First match wins; placeholders keep their names, never the matched values.')
                                ->scalarPrototype()
                                    ->validate()
                                        ->ifTrue(static fn (mixed $t): bool => !is_string($t) || strlen($t) > 512 || preg_match(self::PATH_TEMPLATE_PATTERN, $t) !== 1 || substr_count($t, '/') > self::MAX_PATH_SEGMENTS)
                                        ->thenInvalid('Invalid outbound path template %s: start with "/", literal segments and {name} placeholders only, at most 16 segments.')
                                    ->end()
                                ->end()
                                ->defaultValue([])
                            ->end()
                            ->integerNode('path_depth')
                                ->info('null = default_path_depth')
                                ->defaultNull()
                                ->min(0)
                                ->max(self::MAX_PATH_SEGMENTS)
                            ->end()
                            ->booleanNode('per_call')
                                ->info('false = never a per-call row (uptime probes): counted by the rollup only.')
                                ->defaultTrue()
                            ->end()
                            ->booleanNode('rollup')
                                ->info('Counted in the daily rollup while rollup.enabled.')
                                ->defaultTrue()
                            ->end()
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $p): bool => $p['clients'] === [] && $p['hosts'] === [])
                            ->thenInvalid('An outbound provider needs "clients" or "hosts": %s')
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
            ->end();

        return $node;
    }

    private function mailerNode(): ArrayNodeDefinition
    {
        $node = (new TreeBuilder('mailer'))->getRootNode();
        $node
            ->info('Mailer capture (HL-1 §12): one row per send attempt; never addresses, subject, headers or bodies.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->append($this->retentionDaysNode())
                ->arrayNode('providers')
                    ->info('Provider key => transport schemes; an unmapped scheme is stored as the provider.')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->validate()
                        ->ifTrue(static fn (array $providers): bool => array_filter(array_keys($providers), static fn (int|string $key): bool => !is_string($key) || preg_match(self::PROVIDER_KEY_PATTERN, $key) !== 1) !== [])
                        ->thenInvalid('mailer.providers keys must match ' . self::PROVIDER_KEY_PATTERN . '; got %s')
                    ->end()
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('schemes')
                                ->requiresAtLeastOneElement()
                                ->scalarPrototype()
                                    ->validate()
                                        ->ifTrue(static fn (mixed $s): bool => !is_string($s) || preg_match(self::SCHEME_PATTERN, $s) !== 1)
                                        ->thenInvalid('Invalid mailer transport scheme %s.')
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->defaultValue([])
                ->end()
                ->booleanNode('rollup')
                    ->info('Counted in the daily rollup while rollup.enabled.')
                    ->defaultTrue()
                ->end()
            ->end();

        return $node;
    }

    private function retentionDaysNode(): NodeDefinition
    {
        return (new TreeBuilder('retention_days', 'variable'))->getRootNode()
            ->info('Days the rows of this channel are kept; null keeps them forever.')
            ->defaultValue(30)
            ->validate()
                ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_int($v) || $v < 0))
                ->thenInvalid('Retention days must be a non-negative integer or null')
            ->end();
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
