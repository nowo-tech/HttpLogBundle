<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\DependencyInjection;

use InvalidArgumentException;
use LogicException;
use Nowo\HttpLogBundle\Command\RollupBackfillCommand;
use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\Context\HttpLogContextInterface;
use Nowo\HttpLogBundle\Controller\HttpLogAdminController;
use Nowo\HttpLogBundle\DependencyInjection\Compiler\HttpLogCommitTrackingPass;
use Nowo\HttpLogBundle\Doctrine\ErrorCodeColumnListener;
use Nowo\HttpLogBundle\Doctrine\PathPrefixColumnListener;
use Nowo\HttpLogBundle\Doctrine\TablePrefixListener;
use Nowo\HttpLogBundle\Doctrine\UsageColumnsListener;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogConflictListener;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogSubscriber;
use Nowo\HttpLogBundle\HttpClient\HttpLogHttpClientWrapper;
use Nowo\HttpLogBundle\HttpClient\OutboundCapturePolicy;
use Nowo\HttpLogBundle\HttpClient\OutboundClientFactory;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolution;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolverInterface;
use Nowo\HttpLogBundle\Inbound\HttpRequestContextResolverInterface;
use Nowo\HttpLogBundle\Inbound\InboundChannelMatcher;
use Nowo\HttpLogBundle\Inbound\InboundRequestRecorder;
use Nowo\HttpLogBundle\Inbound\InboundRequestSubscriber;
use Nowo\HttpLogBundle\Inbound\RouteTemplateCacheWarmer;
use Nowo\HttpLogBundle\Inbound\RouteTemplateMap;
use Nowo\HttpLogBundle\Mailer\MailerCaptureListener;
use Nowo\HttpLogBundle\MessageHandler\PersistHttpLogHandler;
use Nowo\HttpLogBundle\Messenger\HttpLogContextMiddleware;
use Nowo\HttpLogBundle\Privacy\HttpLogErasedSubjects;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataSubjectResolverInterface;
use Nowo\HttpLogBundle\Privacy\HttpLogUsageEraser;
use Nowo\HttpLogBundle\Privacy\HttpLogUsageEraserInterface;
use Nowo\HttpLogBundle\Privacy\UserKit\HttpLogUserKitExportProvider;
use Nowo\HttpLogBundle\Privacy\UserKit\HttpLogUserKitPersonalDataEraser;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Rollup\RollupSchemaListener;
use Nowo\HttpLogBundle\Rollup\RollupTables;
use Nowo\HttpLogBundle\Rollup\UsageRollupStore;
use Nowo\HttpLogBundle\Security\ConfigurableHttpLogAccessChecker;
use Nowo\HttpLogBundle\Security\HttpLogAccessCheckerInterface;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use Nowo\HttpLogBundle\Usage\HttpLogChannel;
use Nowo\HttpLogBundle\Usage\HttpLogUsageReader;
use Nowo\HttpLogBundle\Usage\HttpLogUsageReaderInterface;
use Nowo\HttpLogBundle\Usage\UsageBufferFlushSubscriber;
use Nowo\HttpLogBundle\Usage\UsageCaptureSink;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use Nowo\HttpLogBundle\View\AdminChrome;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use Nowo\UserKitBundle\PersonalData\PersonalDataEraserInterface as UserKitPersonalDataEraserInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\Event\MessageEvent;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function class_exists;
use function implode;
use function in_array;
use function interface_exists;
use function is_array;
use function is_bool;
use function is_string;
use function preg_match;
use function sprintf;
use function str_replace;
use function trait_exists;

/**
 * Loads bundle configuration and registers services.
 */
final class NowoHttpLogExtension extends Extension implements PrependExtensionInterface
{
    /** Messenger middleware service id of the HL-1 context (the host adds it to its buses). */
    public const CONTEXT_MIDDLEWARE = 'nowo_http_log.messenger.context_middleware';

    /** Logger channel of the usage capture warnings. */
    public const LOGGER_CHANNEL = 'nowo_http_log';

    /** 1.22: real commits counted by {@see self::COMMIT_TRACKING_MIDDLEWARE}, read by the conflict answer. */
    public const COMMIT_LEDGER_SERVICE = 'nowo_http_log.commit_ledger';

    /** 1.22: transaction-doctrine's commit-tracking DBAL middleware (scoped by {@see HttpLogCommitTrackingPass}). */
    public const COMMIT_TRACKING_MIDDLEWARE = 'nowo_http_log.commit_tracking_middleware';

    /** 1.22: container parameter holding `conflict_response.commit_tracking` (bundle, all or off). */
    public const COMMIT_TRACKING_PARAMETER = 'nowo_http_log.conflict_response.commit_tracking';

    /** Doctrine mapping key of the bundle entity (the bundle name: Doctrine auto_mapping skips a bundle mapped under it). */
    public const MAPPING_NAME = 'NowoHttpLogBundle';

    /** 1.24: directory of the shipped XML mapping (`HttpLogEntry.orm.xml`, same table, columns and indexes). */
    public const XML_MAPPING_DIR = __DIR__ . '/../Resources/config/doctrine';

    /** 1.24: container parameter holding `doctrine.entity_manager` (string, or null for whichever manager maps it). */
    public const ENTITY_MANAGER_PARAMETER = 'nowo_http_log.doctrine.entity_manager';

    /**
     * Feature block => [class or trait that must exist, Composer package that provides it].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const REQUIRED_SYMBOLS = [
        'outbound' => [AsyncDecoratorTrait::class, 'symfony/http-client'],
        'mailer'   => [MessageEvent::class, 'symfony/mailer'],
        // Optional UserKit bridge (GDPR ports, 1.18): registered only when this interface exists.
        'user_kit' => [UserKitPersonalDataEraserInterface::class, 'nowo-tech/user-kit-bundle'],
    ];

    /**
     * @param array<string, array{0: string, 1: string}> $requiredSymbols {@see REQUIRED_SYMBOLS}
     */
    public function __construct(private readonly array $requiredSymbols = self::REQUIRED_SYMBOLS)
    {
    }

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
            $this->prependDoctrineMapping($container);
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
     * 1.24 (DDD host, B3): maps HttpLogEntry as configured by `doctrine.register_mapping`, `mapping_type` and
     * `entity_manager` (read raw: prepend() runs before the configuration is processed; the last file that sets a key
     * wins, load() validates the values again).
     *
     * - register_mapping true, no entity_manager: the 1.23 mapping (attribute, as a bundle mapping) on the default
     *   manager, or the shipped XML with `mapping_type: xml`.
     * - register_mapping true with entity_manager: the same mapping on that manager only. Doctrine auto_mapping skips a
     *   bundle that any manager maps under its name, so the default manager no longer maps the entity.
     * - register_mapping false: nothing is mapped; a disabled mapping under the bundle name keeps auto_mapping from
     *   mapping the entity on the auto-mapped manager, unless the host already uses that mapping name itself.
     */
    private function prependDoctrineMapping(ContainerBuilder $container): void
    {
        [$register, $type, $manager] = $this->rawDoctrineOptions($container);
        $doctrine                    = $container->getExtensionConfig('doctrine');

        if ($register) {
            $mapping = $type === Configuration::MAPPING_TYPE_XML
                ? ['type' => 'xml', 'dir' => self::XML_MAPPING_DIR, 'prefix' => HttpLogCommitTrackingPass::ENTITY_NAMESPACE, 'is_bundle' => false]
                : ['type' => 'attribute', 'is_bundle' => true];

            if ($manager === null) {
                $container->prependExtensionConfig('doctrine', ['orm' => ['mappings' => [self::MAPPING_NAME => $mapping]]]);

                return;
            }

            if (!in_array($manager, $this->declaredManagers($doctrine), true)) {
                throw new InvalidConfigurationException(sprintf('nowo_http_log.doctrine.entity_manager "%s" is not a configured entity manager: declare it under doctrine.orm.entity_managers (configured: %s).', $manager, implode(', ', $this->declaredManagers($doctrine))));
            }

            $container->prependExtensionConfig('doctrine', ['orm' => ['entity_managers' => [$manager => ['mappings' => [self::MAPPING_NAME => $mapping]]]]]);

            return;
        }

        if ($this->hostMapsTheBundleName($doctrine)) {
            return;
        }

        $target = $manager ?? $this->declaredManagers($doctrine)[0] ?? null;
        if ($target === null) {
            return;
        }

        $container->prependExtensionConfig('doctrine', ['orm' => ['entity_managers' => [$target => ['mappings' => [self::MAPPING_NAME => ['mapping' => false]]]]]]);
    }

    /**
     * Raw `doctrine.register_mapping`, `mapping_type` and `entity_manager` of the bundle configuration.
     *
     * @return array{0: bool, 1: string, 2: string|null}
     */
    private function rawDoctrineOptions(ContainerBuilder $container): array
    {
        $register = true;
        $type     = Configuration::MAPPING_TYPE_ATTRIBUTE;
        $manager  = null;
        foreach ($container->getExtensionConfig($this->getAlias()) as $config) {
            $doctrine = is_array($config['doctrine'] ?? null) ? $config['doctrine'] : [];
            if (array_key_exists('register_mapping', $doctrine)) {
                if (!is_bool($doctrine['register_mapping'])) {
                    throw new InvalidConfigurationException('nowo_http_log.doctrine.register_mapping must be a literal boolean: it decides the Doctrine mapping at compile time (no %env()%).');
                }
                $register = $doctrine['register_mapping'];
            }
            if (in_array($doctrine['mapping_type'] ?? null, [Configuration::MAPPING_TYPE_ATTRIBUTE, Configuration::MAPPING_TYPE_XML], true)) {
                $type = $doctrine['mapping_type'];
            }
            if (array_key_exists('entity_manager', $doctrine)) {
                $manager = is_string($doctrine['entity_manager']) && $doctrine['entity_manager'] !== '' ? $doctrine['entity_manager'] : null;
            }
        }

        if ($manager !== null && preg_match(Configuration::MANAGER_NAME_PATTERN, $manager) !== 1) {
            throw new InvalidConfigurationException(sprintf('Invalid "nowo_http_log.doctrine.entity_manager" "%s": use a literal entity manager name (letters, digits and underscores; no %%env()%%) or null.', $manager));
        }

        return [$register, $type, $manager];
    }

    /**
     * Entity manager names the host declares, the default one first: `default_entity_manager`, then every
     * `entity_managers` key; `default` for a short-form `orm` block (DoctrineBundle's name for it).
     *
     * @param array<array<string, mixed>> $doctrine raw `doctrine` configurations
     *
     * @return list<string>
     */
    private function declaredManagers(array $doctrine): array
    {
        $default  = null;
        $managers = [];
        foreach ($doctrine as $config) {
            $orm = $config['orm'] ?? null;
            if (!is_array($orm)) {
                continue;
            }
            if (is_string($orm['default_entity_manager'] ?? null) && $orm['default_entity_manager'] !== '') {
                $default = $orm['default_entity_manager'];
            }
            if (is_array($orm['entity_managers'] ?? null)) {
                foreach (array_keys($orm['entity_managers']) as $name) {
                    $managers[] = (string) $name;
                }
            } elseif (is_array($orm['entity_manager'] ?? null)) {
                foreach (array_keys($orm['entity_manager']) as $name) {
                    $managers[] = (string) $name;
                }
            } else {
                $managers[] = $default ?? 'default';
            }
        }

        if ($default !== null) {
            $managers = [$default, ...$managers];
        }

        return array_values(array_unique($managers));
    }

    /**
     * True when a host configuration already maps something under the bundle name (on any manager).
     *
     * @param array<array<string, mixed>> $doctrine raw `doctrine` configurations
     */
    private function hostMapsTheBundleName(array $doctrine): bool
    {
        foreach ($doctrine as $config) {
            $orm = is_array($config['orm'] ?? null) ? $config['orm'] : [];
            if (is_array($orm['mappings'] ?? null) && array_key_exists(self::MAPPING_NAME, $orm['mappings'])) {
                return true;
            }
            $managers = is_array($orm['entity_managers'] ?? null) ? $orm['entity_managers'] : (is_array($orm['entity_manager'] ?? null) ? $orm['entity_manager'] : []);
            foreach ($managers as $manager) {
                if (is_array($manager) && is_array($manager['mappings'] ?? null) && array_key_exists(self::MAPPING_NAME, $manager['mappings'])) {
                    return true;
                }
            }
        }

        return false;
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

        // REQ-ORM-002: the table naming listener only exists when a name differs from the mapped one.
        $container->setParameter($alias . '.doctrine.table_prefix', $config['doctrine']['table_prefix']);
        $container->setParameter($alias . '.doctrine.table_name_prefix', $config['doctrine']['table_name_prefix']);
        // 1.24: the mapping switch was applied in prepend(); the manager name is checked by HttpLogEntityManagerPass.
        foreach (['mapping_type', 'entity_manager'] as $option) {
            $value = $config['doctrine'][$option];
            if (is_string($value) && $container->resolveEnvPlaceholders($value) !== $value) {
                throw new InvalidConfigurationException(sprintf('nowo_http_log.doctrine.%s does not support %%env()%%: it decides the Doctrine mapping at compile time; set a literal value.', $option));
            }
        }
        $container->setParameter($alias . '.doctrine.register_mapping', $config['doctrine']['register_mapping']);
        $container->setParameter($alias . '.doctrine.mapping_type', $config['doctrine']['mapping_type']);
        $container->setParameter(self::ENTITY_MANAGER_PARAMETER, $config['doctrine']['entity_manager']);
        $container->setParameter($alias . '.web_ui.filter_match', $config['web_ui']['filter_match']);
        if ($config['web_ui']['filter_match'] === Configuration::FILTER_MATCH_PREFIX) {
            // Prefix filters (PERF-1147-02): map path_prefix and its index, before TablePrefixListener (priority 0).
            $container->setDefinition(PathPrefixColumnListener::class, (new Definition(PathPrefixColumnListener::class))
                ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata', 'priority' => 100]));
        }
        [$usageColumns, $rollup] = $this->registerUsage($container, $config);
        $this->registerTableName($container, $config['doctrine']['table_prefix'], $config['doctrine']['table_name_prefix'], $usageColumns, $rollup);
        if ($config['doctrine']['table_prefix'] === '' && $config['doctrine']['table_name_prefix'] === TablePrefixListener::DEFAULT_TABLE_NAME_PREFIX) {
            $container->removeDefinition(TablePrefixListener::class);
        }

        $this->registerAccessChecker($container, $config['security']);
        $this->registerPersonalData($container, $config['personal_data']);
        $this->registerConflictAnswer($container, $config['conflict_response'], $config['web_ui']['enabled']);
    }

    /**
     * 1.22 (REQ-RUNTIME-003 rule 6): 409 (or 503) + CONFLICT_001 / CONFLICT_002 instead of a 500 for lock conflicts on
     * the bundle's admin routes. Unset options follow transaction-doctrine's policy service when its bundle is
     * registered. Nothing is registered without the web UI (the bundle then has no route).
     *
     * Commit tracking (`conflict_response.commit_tracking`): `off` (the default since 1.23, audit UV-2: a purge reports a
     * deleted batch itself) registers neither the ledger nor the middleware. `bundle` and `all` register
     * transaction-doctrine's commit-tracking middleware; {@see HttpLogCommitTrackingPass} scopes it to the connections of
     * the managers that map the entries (`bundle`), or drops it and reuses transaction-doctrine's own ledger when that
     * bundle's tagged commit-tracking middleware is registered.
     *
     * @param array{status: int|null, retry_after: int|null, commit_tracking: string} $conflictResponse
     */
    private function registerConflictAnswer(ContainerBuilder $container, array $conflictResponse, bool $webUi): void
    {
        if (!$webUi) {
            return;
        }

        $container->setParameter(self::COMMIT_TRACKING_PARAMETER, $conflictResponse['commit_tracking']);
        $tracking = $conflictResponse['commit_tracking'] !== 'off';
        if ($tracking) {
            $container->setDefinition(self::COMMIT_LEDGER_SERVICE, new Definition(CommitLedger::class));
            $container->setDefinition(self::COMMIT_TRACKING_MIDDLEWARE, (new Definition(CommitTrackingMiddleware::class, [new Reference(self::COMMIT_LEDGER_SERVICE)]))
                ->addTag('doctrine.middleware'));
        }

        $policy = $conflictResponse['status'] === null && $conflictResponse['retry_after'] === null
            ? new Reference(ConflictResponsePolicy::class, ContainerInterface::NULL_ON_INVALID_REFERENCE)
            : new Definition(ConflictResponsePolicy::class, [
                $conflictResponse['status'] ?? ConflictResponsePolicy::STATUS_CONFLICT,
                $conflictResponse['retry_after'] ?? ConflictResponsePolicy::DEFAULT_RETRY_AFTER,
            ]);

        $container->setDefinition(HttpLogConflictListener::class, (new Definition(HttpLogConflictListener::class, [
            $policy,
            $tracking ? new Reference(self::COMMIT_LEDGER_SERVICE) : null,
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))
            ->addTag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => HttpLogConflictListener::REQUEST_PRIORITY])
            ->addTag('kernel.event_listener', ['event' => KernelEvents::EXCEPTION, 'method' => 'onException', 'priority' => HttpLogConflictListener::PRIORITY]));
    }

    /**
     * GDPR ports (1.18): the export limit, the subject resolver and, with UserKit installed and
     * `personal_data.enabled`, the `http_log` eraser and the `http_requests` export section.
     *
     * @param array{enabled: bool, export_limit: int, subject_resolver: string|null} $personalData
     */
    private function registerPersonalData(ContainerBuilder $container, array $personalData): void
    {
        $container->setParameter(Configuration::ALIAS . '.personal_data.export_limit', $personalData['export_limit']);
        if ($personalData['subject_resolver'] !== null) {
            $container->setAlias(HttpLogPersonalDataSubjectResolverInterface::class, $personalData['subject_resolver']);
        }

        $userKit = $this->requiredSymbols['user_kit'][0] ?? UserKitPersonalDataEraserInterface::class;
        if (!$personalData['enabled'] || !interface_exists($userKit)) {
            return;
        }
        $container->register(HttpLogUserKitPersonalDataEraser::class, HttpLogUserKitPersonalDataEraser::class)
            ->setAutowired(true)
            ->addTag('nowo_user_kit.personal_data.eraser', ['priority' => HttpLogUserKitPersonalDataEraser::PRIORITY]);
        $container->register(HttpLogUserKitExportProvider::class, HttpLogUserKitExportProvider::class)
            ->setAutowired(true)
            ->addTag('nowo_user_kit.personal_data.export_provider', ['priority' => HttpLogUserKitExportProvider::PRIORITY]);
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

    /**
     * HL-1 usage capture (named inbound channels, outbound HttpClient, Mailer, daily rollup). Every service of the
     * feature is registered here, only when its block is enabled: hosts that enable nothing get no listener,
     * decorator, middleware use or schema change. Always registered (inert until used): the {@see HttpLogContext},
     * the client wrapper and, with `messenger.middleware`, the context middleware (private: removed from the compiled
     * container unless a bus references it).
     *
     * @param array<string, mixed> $config processed configuration
     *
     * @return array{0: bool, 1: bool} [the usage columns are mapped (a named channel, outbound or mailer), the rollup tables are mapped]
     */
    private function registerUsage(ContainerBuilder $container, array $config): array
    {
        /** @var array{enabled: mixed, retention_days: int|null} $outbound */
        $outbound = $config['outbound'];
        /** @var array{enabled: mixed, retention_days: int|null, providers: array<string, array{schemes: list<string>}>} $mailer */
        $mailer = $config['mailer'];
        /** @var array{middleware: bool, propagate_tenant: bool} $messenger */
        $messenger = $config['messenger'];
        /** @var array<string, array{path_prefixes: list<string>, routes: list<string>, exclude_path_prefixes: list<string>, exclude_routes: list<string>, sampling_rate: float, error_status_min: int, retention_days: int|null, client_ip: string, user: bool, rollup: bool}> $named */
        $named = $config['channels'];
        /** @var array{enabled: mixed, retention_months: int|null, dedupe_days: int, web: bool} $rollupConfig */
        $rollupConfig = $config['rollup'];

        $container->registerForAutoconfiguration(HttpLogRequestContextResolverInterface::class)
            ->addTag(HttpLogRequestContextResolverInterface::TAG);
        $container->registerForAutoconfiguration(HttpRequestContextResolverInterface::class)
            ->addTag(HttpLogRequestContextResolution::RESOLVER_TAG);

        foreach (['outbound' => $outbound['enabled'], 'mailer' => $mailer['enabled'], 'rollup' => $rollupConfig['enabled']] as $block => $enabled) {
            if (!is_bool($enabled)) {
                throw new InvalidConfigurationException(sprintf('nowo_http_log.%s.enabled must be a literal boolean: it decides the Doctrine mapping at compile time (no %%env()%%).', $block));
            }
        }
        $outboundEnabled = $outbound['enabled'] === true;
        $mailerEnabled   = $mailer['enabled'] === true;
        foreach (['outbound' => $outboundEnabled, 'mailer' => $mailerEnabled] as $block => $enabled) {
            [$symbol, $package] = $this->requiredSymbols[$block];
            if ($enabled && !class_exists($symbol) && !trait_exists($symbol)) {
                throw new LogicException(sprintf('nowo_http_log.%s.enabled requires %s.', $block, $package));
            }
        }

        $alias         = Configuration::ALIAS;
        $rollupEnabled = $rollupConfig['enabled'] === true;
        $usageColumns  = $outboundEnabled || $mailerEnabled || $named !== [];
        $channels      = [];
        if ($outboundEnabled) {
            $channels[HttpLogChannel::OUTBOUND] = $outbound['retention_days'];
        }
        if ($mailerEnabled) {
            $channels[HttpLogChannel::MAILER] = $mailer['retention_days'];
        }
        foreach ($named as $name => $channel) {
            $channels[$name] = $channel['retention_days'];
        }
        $container->setParameter($alias . '.usage.enabled', $usageColumns);
        $container->setParameter($alias . '.usage.channels', array_keys($channels));
        $container->setParameter($alias . '.usage.channel_retention', $channels);
        $container->setParameter($alias . '.rollup.enabled', $rollupEnabled);
        $container->setParameter($alias . '.outbound.enabled', $outboundEnabled);
        $container->setParameter($alias . '.outbound.clients', $config['outbound']['clients']);
        $container->setParameter($alias . '.outbound.exclude_clients', $config['outbound']['exclude_clients']);
        $container->setParameter($alias . '.outbound.decoration_priority', $config['outbound']['decoration_priority']);

        $logger = new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE);

        $container->setDefinition(HttpLogContext::class, (new Definition(HttpLogContext::class))
            ->setPublic(true)
            ->addTag('kernel.reset', ['method' => 'reset']));
        $container->setAlias(HttpLogContextInterface::class, HttpLogContext::class)->setPublic(true);

        if ($messenger['middleware']) {
            $container->setDefinition(self::CONTEXT_MIDDLEWARE, (new Definition(HttpLogContextMiddleware::class))
                ->setArguments([new Reference(HttpLogContext::class), $messenger['propagate_tenant'], $logger])
                ->addTag('monolog.logger', ['channel' => self::LOGGER_CHANNEL]));
        }

        if ($rollupEnabled) {
            $this->registerRollup($container, $config, $named);
        }

        if ($usageColumns) {
            $container->setDefinition(UsageColumnsListener::class, (new Definition(UsageColumnsListener::class))
                // Before TablePrefixListener (priority 0), which then prefixes the usage index names too.
                ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata', 'priority' => 100]));
            if ($outboundEnabled && array_filter($config['outbound']['providers'], static fn (array $provider): bool => $provider['error_code']['paths'] !== []) !== []) {
                // error_code: mapped only while a provider reads it (no migration for hosts that do not)
                $container->setDefinition(ErrorCodeColumnListener::class, (new Definition(ErrorCodeColumnListener::class))
                    ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata', 'priority' => 100]));
            }
        }

        if ($usageColumns || $rollupEnabled) {
            $container->setDefinition(UsageEntryWriter::class, (new Definition(UsageEntryWriter::class))
                ->setArguments([
                    new Reference('doctrine'),
                    $rollupEnabled ? new Reference(UsageRollupStore::class) : null,
                    new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    $logger,
                ])
                ->addTag('monolog.logger', ['channel' => self::LOGGER_CHANNEL]));
            $container->setDefinition(UsageCaptureSink::class, (new Definition(UsageCaptureSink::class))
                ->setArguments([
                    new Reference(UsageEntryWriter::class),
                    $config['async'],
                    new Reference('messenger.default_bus', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                ]));
            $container->setDefinition(HttpLogBuffer::class, (new Definition(HttpLogBuffer::class))
                ->setArguments([new Reference(UsageCaptureSink::class), $logger, new Reference(HttpLogErasedSubjects::class)])
                ->addTag('kernel.reset', ['method' => 'reset'])
                ->addTag('monolog.logger', ['channel' => self::LOGGER_CHANNEL]));
            $container->setDefinition(UsageBufferFlushSubscriber::class, (new Definition(UsageBufferFlushSubscriber::class))
                ->setArguments([new Reference(HttpLogBuffer::class)])
                ->addTag('kernel.event_subscriber'));
            $container->getDefinition(PersistHttpLogHandler::class)
                ->setArgument('$usageWriter', new Reference(UsageEntryWriter::class));
            // the web channel's own dispatch / write suspends outbound capture as well (IX-L3)
            $container->getDefinition(HttpLogRecorder::class)
                ->setArgument('$buffer', new Reference(HttpLogBuffer::class));

            $container->setDefinition(HttpLogUsageReader::class, (new Definition(HttpLogUsageReader::class))
                ->setArguments([new Reference('doctrine'), $rollupEnabled ? new Reference(UsageRollupStore::class) : null]));
            $container->setAlias(HttpLogUsageReaderInterface::class, HttpLogUsageReader::class)->setPublic(true);
            $container->setDefinition(HttpLogUsageEraser::class, (new Definition(HttpLogUsageEraser::class))
                ->setArguments([
                    new Reference(HttpLogEntryRepository::class),
                    $rollupEnabled ? new Reference(UsageRollupStore::class) : null,
                    '%' . $alias . '.retention%',
                ]));
            $container->setAlias(HttpLogUsageEraserInterface::class, HttpLogUsageEraser::class)->setPublic(true);
        }

        if ($named !== [] || ($rollupEnabled && $rollupConfig['web'])) {
            $this->registerInbound($container, $config, $named, $rollupEnabled, $logger);
        }

        if ($outboundEnabled) {
            $providers = [];
            foreach ($config['outbound']['providers'] as $key => $provider) {
                $providers[$key] = [
                    'clients'        => $provider['clients'],
                    'hosts'          => $provider['hosts'],
                    'path_templates' => $provider['path_templates'],
                    'path_depth'     => $provider['path_depth'],
                    'per_call'       => $provider['per_call'],
                ];
                if ($rollupEnabled) {
                    $providers[$key]['rollup'] = $provider['rollup'];
                }
                if ($provider['error_code']['paths'] !== []) {
                    $providers[$key]['error_code'] = $provider['error_code'];
                }
            }
            $container->setDefinition(OutboundCapturePolicy::class, (new Definition(OutboundCapturePolicy::class))
                ->setArguments([
                    $providers,
                    $config['outbound']['sampling_rate'],
                    $config['outbound']['record_unmapped'],
                    $config['outbound']['default_path_depth'],
                    $config['outbound']['path_words'],
                    $config['outbound']['exclude_hosts'],
                    ...($rollupEnabled ? [true] : []),
                ]));
            $container->setDefinition(OutboundClientFactory::class, (new Definition(OutboundClientFactory::class))
                ->setArguments([
                    new Reference(OutboundCapturePolicy::class),
                    new Reference(HttpLogBuffer::class),
                    new Reference(HttpLogContext::class),
                    new Reference(ClockInterface::class),
                    $logger,
                ])
                ->addTag('monolog.logger', ['channel' => self::LOGGER_CHANNEL]));
        }

        if ($mailerEnabled) {
            $schemes = [];
            foreach ($mailer['providers'] as $key => $provider) {
                foreach ($provider['schemes'] as $scheme) {
                    $schemes[$scheme] = $key;
                }
            }
            $container->setDefinition(MailerCaptureListener::class, (new Definition(MailerCaptureListener::class))
                ->setArguments([
                    new Reference(HttpLogBuffer::class),
                    new Reference(HttpLogContext::class),
                    new Reference(ClockInterface::class),
                    $schemes,
                    $logger,
                    $rollupEnabled && $config['mailer']['rollup'],
                ])
                ->addTag('kernel.event_subscriber')
                ->addTag('kernel.reset', ['method' => 'reset'])
                ->addTag('monolog.logger', ['channel' => self::LOGGER_CHANNEL]));
        }

        // Public wrapper for clients built in code: returns them unchanged while outbound capture is disabled.
        if (trait_exists($this->requiredSymbols['outbound'][0])) {
            $container->setDefinition(HttpLogHttpClientWrapper::class, (new Definition(HttpLogHttpClientWrapper::class))
                ->setPublic(true)
                ->setArguments([$outboundEnabled ? new Reference(OutboundClientFactory::class) : null]));
        }

        return [$usageColumns, $rollupEnabled];
    }

    /**
     * Daily rollup (HL-1 §8.3, §9): schema listener, store, backfill command; purge scopes `rollup` and `ledger`.
     *
     * @param array<string, mixed> $config processed configuration
     * @param array<string, array{sampling_rate: float, error_status_min: int, retention_days: int|null, rollup: bool}> $named
     */
    private function registerRollup(ContainerBuilder $container, array $config, array $named): void
    {
        /** @var array{table_prefix: string, table_name_prefix: string} $doctrine */
        $doctrine = $config['doctrine'];
        /** @var array{retention_months: int|null, dedupe_days: int, web: bool} $rollup */
        $rollup = $config['rollup'];

        $container->setDefinition(RollupTables::class, (new Definition(RollupTables::class))
            ->setFactory([RollupTables::class, 'fromPrefixes'])
            ->setArguments([$doctrine['table_prefix'], $doctrine['table_name_prefix']]));
        $container->setDefinition(RollupSchemaListener::class, (new Definition(RollupSchemaListener::class))
            ->setArguments([new Reference(RollupTables::class)])
            ->addTag('doctrine.event_listener', ['event' => 'postGenerateSchema']));

        // a counted per-call row is its own dedupe key: it must outlive the ledger window (JA-L2)
        $windows = [];
        foreach ($named as $name => $channel) {
            if ($channel['rollup']) {
                $windows['channels.' . $name] = $channel['retention_days'];
            }
        }
        if ($config['outbound']['enabled'] === true) {
            $windows['outbound'] = $config['outbound']['retention_days'];
        }
        if ($config['mailer']['enabled'] === true && $config['mailer']['rollup']) {
            $windows['mailer'] = $config['mailer']['retention_days'];
        }
        foreach ($windows as $path => $days) {
            if ($days !== null && $days < $rollup['dedupe_days']) {
                throw new InvalidConfigurationException(sprintf('nowo_http_log.%s.retention_days (%d) must be at least rollup.dedupe_days (%d): a counted row is the dedupe key of its event and must outlive the ledger window.', $path, $days, $rollup['dedupe_days']));
            }
        }

        $errorStatusMin = [];
        $backfill       = [];
        foreach ($named as $name => $channel) {
            $errorStatusMin[$name] = $channel['error_status_min'];
            if ($channel['rollup']) {
                $backfill[$name] = $channel['sampling_rate'] < 1.0;
            }
        }
        $excludedProviders = [];
        if ($config['outbound']['enabled'] === true) {
            $backfill[HttpLogChannel::OUTBOUND] = $config['outbound']['sampling_rate'] < 1.0;
            foreach ($config['outbound']['providers'] as $key => $provider) {
                if (!$provider['rollup']) {
                    $excludedProviders[] = (string) $key;
                }
            }
        }
        if ($config['mailer']['enabled'] === true && $config['mailer']['rollup']) {
            $backfill[HttpLogChannel::MAILER] = false;
        }
        if ($rollup['web']) {
            $backfill[HttpLogChannel::WEB] = $config['sampling_rate'] < 1.0;
        }

        $container->setDefinition(UsageRollupStore::class, (new Definition(UsageRollupStore::class))
            ->setArguments([
                new Reference('doctrine'),
                new Reference(RollupTables::class),
                new Reference(ClockInterface::class),
                $rollup['retention_months'],
                $rollup['dedupe_days'],
                $errorStatusMin,
            ]));
        $container->setDefinition(RollupBackfillCommand::class, (new Definition(RollupBackfillCommand::class))
            ->setArguments([new Reference(UsageRollupStore::class), new Reference('doctrine'), $backfill, $excludedProviders])
            ->setAutoconfigured(true));
        $container->getDefinition(PurgeHttpLogService::class)
            ->setArgument('$rollup', new Reference(UsageRollupStore::class));
    }

    /**
     * Named inbound channels (HL-1 §6) and the `rollup.web` count: matcher, route template map and its warmer, the
     * resolver hook, the request-side subscriber and the recorder the web subscriber hands matched requests to.
     *
     * @param array<string, mixed> $config processed configuration
     * @param array<string, array<string, mixed>> $named
     */
    private function registerInbound(ContainerBuilder $container, array $config, array $named, bool $rollupEnabled, Reference $logger): void
    {
        $container->setDefinition(InboundChannelMatcher::class, (new Definition(InboundChannelMatcher::class))
            ->setFactory([InboundChannelMatcher::class, 'fromConfig'])
            ->setArguments([$named]));
        $container->setDefinition(RouteTemplateMap::class, (new Definition(RouteTemplateMap::class))
            ->setArguments([
                new Reference('router'),
                new Reference(InboundChannelMatcher::class),
                new Reference('config_cache_factory'),
                '%kernel.cache_dir%',
                '%kernel.debug%',
            ])
            ->addTag('kernel.reset', ['method' => 'reset']));
        $container->setDefinition(RouteTemplateCacheWarmer::class, (new Definition(RouteTemplateCacheWarmer::class))
            ->setArguments([new Reference(RouteTemplateMap::class)])
            ->addTag('kernel.cache_warmer'));
        $container->setDefinition(HttpLogRequestContextResolution::class, (new Definition(HttpLogRequestContextResolution::class))
            ->setArguments([
                new TaggedIteratorArgument(HttpLogRequestContextResolverInterface::TAG),
                $config['redaction']['headers'],
                $config['redaction']['query_params'],
                $logger,
                new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference('security.helper', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference('security.untracked_token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                $config['redaction']['credential_headers'],
                $config['redaction']['context_headers'],
                $config['redaction']['context_attributes'],
            ])
            ->addTag('monolog.logger', ['channel' => self::LOGGER_CHANNEL]));
        $container->setDefinition(InboundRequestRecorder::class, (new Definition(InboundRequestRecorder::class))
            ->setArguments([
                new Reference(InboundChannelMatcher::class),
                new Reference(RouteTemplateMap::class),
                new Reference(HttpLogRequestContextResolution::class),
                new Reference(HttpLogBuffer::class),
                new Reference(ClockInterface::class),
                $logger,
                $rollupEnabled,
                $rollupEnabled && $config['rollup']['web'],
                new Reference(HttpLogErasedSubjects::class),
            ])
            ->addTag('monolog.logger', ['channel' => self::LOGGER_CHANNEL]));
        $container->setDefinition(InboundRequestSubscriber::class, (new Definition(InboundRequestSubscriber::class))
            ->setArguments([
                new Reference(InboundRequestRecorder::class),
                new Reference(HttpLogRequestContextResolution::class),
                new Reference(HttpLogContext::class),
                $logger,
            ])
            ->addTag('kernel.event_subscriber')
            ->addTag('monolog.logger', ['channel' => self::LOGGER_CHANNEL]));
        $container->getDefinition(HttpLogSubscriber::class)
            ->setArgument('$inbound', new Reference(InboundRequestRecorder::class));
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

    /**
     * REQ-ORM-002: validates the literal prefixes (`%env()%` values are checked by the listener once resolved) and
     * publishes `nowo_http_log.doctrine.entry_table`, the physical table name, for host SQL and reports.
     */
    private function registerTableName(ContainerBuilder $container, string $tablePrefix, string $tableNamePrefix, bool $usageColumns = false, bool $rollup = false): void
    {
        // Table names are part of the schema: %env()% is not supported, so every published name is validated.
        if ($container->resolveEnvPlaceholders($tablePrefix) !== $tablePrefix || $container->resolveEnvPlaceholders($tableNamePrefix) !== $tableNamePrefix) {
            throw new InvalidConfigurationException('doctrine.table_prefix and doctrine.table_name_prefix do not support %env()%: the table names are part of the schema; set literal values.');
        }
        try {
            TablePrefixListener::validate($tablePrefix, $tableNamePrefix, $usageColumns, $rollup);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidConfigurationException($exception->getMessage(), 0, $exception);
        }

        $container->setParameter($this->getAlias() . '.doctrine.entry_table', $tablePrefix . $tableNamePrefix . TablePrefixListener::ENTRY_TABLE_SUFFIX);
    }
}
