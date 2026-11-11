<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\DependencyInjection;

use InvalidArgumentException;
use LogicException;
use Nowo\HttpLogBundle\Command\RollupBackfillCommand;
use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\Controller\HttpLogAdminController;
use Nowo\HttpLogBundle\Doctrine\PathPrefixColumnListener;
use Nowo\HttpLogBundle\Doctrine\TablePrefixListener;
use Nowo\HttpLogBundle\Doctrine\UsageColumnsListener;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogSubscriber;
use Nowo\HttpLogBundle\HttpClient\HttpLogHttpClientWrapper;
use Nowo\HttpLogBundle\HttpClient\OutboundCapturePolicy;
use Nowo\HttpLogBundle\HttpClient\OutboundClientFactory;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolution;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolverInterface;
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
use Symfony\Component\Mailer\Event\MessageEvent;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function class_exists;
use function interface_exists;
use function is_array;
use function is_bool;
use function is_string;
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

        // REQ-ORM-002: the table naming listener only exists when a name differs from the mapped one.
        $container->setParameter($alias . '.doctrine.table_prefix', $config['doctrine']['table_prefix']);
        $container->setParameter($alias . '.doctrine.table_name_prefix', $config['doctrine']['table_name_prefix']);
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
        }

        if ($usageColumns || $rollupEnabled) {
            $container->setDefinition(UsageEntryWriter::class, (new Definition(UsageEntryWriter::class))
                ->setArguments([new Reference('doctrine'), $rollupEnabled ? new Reference(UsageRollupStore::class) : null]));
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
