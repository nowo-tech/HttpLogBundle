# Code inventory — HttpLogBundle baseline

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/http-log-bundle`  
**Last audited**: 2026-11-16 (usage channels, rollup, privacy ports, exact collation; REQ sweep)

Maps **100%** of production files under `src/` (132 units; audit: `git ls-files src | wc -l` → 132). Test and demo trees are out of Packagist scope unless promoted in the spec.

## Bundle entry

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `NowoHttpLogBundle.php` | Bundle entrypoint | FR-BUNDLE-001 |

## DependencyInjection

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/NowoHttpLogExtension.php` | DI extension | FR-CFG-002, FR-DI-001 |
| `DependencyInjection/Compiler/TwigPathsPass.php` | Twig override path order | FR-TWIG-001 |
| `DependencyInjection/LegacyThemeBridge.php` | 1.x chrome keys → UiKit theme slots (deprecated) | FR-UI-003, 004: FR-UIC-005 |

## Entity & persistence

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Entity/HttpLogEntry.php` | Doctrine entity | FR-ORM-001 |
| `Repository/HttpLogEntryRepository.php` | Query / pagination | FR-ORM-001, FR-ADM-001 |
| `Doctrine/TablePrefixListener.php` | `doctrine.table_name_prefix` / `doctrine.table_prefix` metadata listener | FR-ORM-002 |
| `Privacy/HttpLogPersonalDataEraserInterface.php`, `Privacy/HttpLogPersonalDataEraser.php` | GDPR erasure API (count / batched delete by user identifier or client IP) | FR-ORM-002 |

## Domain model & enums

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Model/HttpLogCapture.php` | Capture DTO | FR-CAP-001 |
| `Enum/BodyContentType.php` | Content-type enum | FR-CAP-003 |
| `Enum/CssFramework.php` | Admin UI CSS stack | FR-UI-001 |
| `Enum/ExportFormat.php` | Export format enum | FR-EXP-001 |
| `View/AdminChrome.php` | Admin chrome defaults (templates, CSS class map, switches) | FR-CHR-001..FR-CHR-008 (spec 002) |

## Services

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Service/HttpLogRecorder.php` | Orchestrates capture + persist | FR-CAP-001, FR-RED-001 |
| `Service/CapturePolicy.php` | Environment / route / sampling filters | FR-CAP-001, FR-IGN-001 |
| `Service/ContentTypeClassifier.php` | Body content-type detection | FR-CAP-003 |
| `Service/HttpLogRedactor.php` | Header/query/JSON redaction | FR-RED-001 |
| `Service/ExportHttpLogService.php` | CSV/JSON export | FR-EXP-001 |
| `Service/PurgeHttpLogService.php` | Retention purge | FR-PUR-001 |

## HTTP & events

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `EventSubscriber/HttpLogSubscriber.php` | Kernel capture on terminate | FR-CAP-001, FR-CAP-002, FR-IGN-001, FR-IGN-002 |
| `Controller/HttpLogAdminController.php` | Admin UI routes | FR-ADM-001, FR-UI-002 |

## Forms

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Form/HttpLogFilterType.php` | Admin list filters | FR-ADM-001 |
| `Form/AbstractHttpLogActionType.php` | Base for CSRF-protected admin action forms (flat `_token`) | FR-ADM-001 |
| `Form/HttpLogExportType.php` | Export action form (format + criteria hidden fields) | FR-EXP-001 |
| `Form/HttpLogPurgeType.php` | Purge action form | FR-PUR-001 |
| `Form/HttpLogDeleteType.php` | Delete-entry action form | FR-ADM-001 |

## Security

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Security/HttpLogAccessCheckerInterface.php` | Access checker contract | FR-UI-002 |
| `Security/ConfigurableHttpLogAccessChecker.php` | Default role-based checker | FR-UI-002 |

## Messenger

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Message/PersistHttpLogMessage.php` | Async persist DTO | FR-MSG-001 |
| `Message/ExportHttpLogMessage.php` | Async export DTO | FR-MSG-001, FR-EXP-001 |
| `Message/PurgeHttpLogMessage.php` | Async purge DTO | FR-MSG-001, FR-PUR-001 |
| `MessageHandler/PersistHttpLogHandler.php` | Persist handler | FR-MSG-001 |
| `MessageHandler/ExportHttpLogHandler.php` | Export handler | FR-MSG-001 |
| `MessageHandler/PurgeHttpLogHandler.php` | Purge handler | FR-MSG-001 |

## CLI

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Command/ExportHttpLogCommand.php` | Console export | FR-EXP-001 |
| `Command/PurgeHttpLogCommand.php` | Console purge | FR-PUR-001 |

## Symfony config (`src/Resources/config/`)

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Resources/config/services.yaml` | Service wiring | FR-DI-001 |
| `Resources/config/routes.yaml` | Admin attribute routes | FR-ADM-001 |
| `Resources/config/packages/nowo_http_log.yaml` | Default package config | FR-CFG-001 |

## Translations (`src/Resources/translations/`)

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Resources/translations/NowoHttpLogBundle.en.yaml` | English catalogue | FR-I18N-002, FR-I18N-003 |
| `Resources/translations/NowoHttpLogBundle.es.yaml` | Spanish catalogue | FR-I18N-002 |
| `Resources/translations/NowoHttpLogBundle.fr.yaml` | French catalogue | FR-I18N-002 |
| `Resources/translations/NowoHttpLogBundle.de.yaml` | German catalogue | FR-I18N-002 |
| `Resources/translations/NowoHttpLogBundle.it.yaml` | Italian catalogue | FR-I18N-002 |
| `Resources/translations/NowoHttpLogBundle.nl.yaml` | Dutch catalogue | FR-I18N-002 |
| `Resources/translations/NowoHttpLogBundle.pt.yaml` | Portuguese catalogue | FR-I18N-002 |

## Twig views (`src/Resources/views/`)

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Resources/views/admin/index.html.twig` | List page | FR-ADM-001, FR-TWIG-001 |
| `Resources/views/admin/show.html.twig` | Detail page | FR-ADM-001, FR-TWIG-001 |
| `Resources/views/admin/_filter.html.twig` | Filter partial | FR-ADM-001, FR-TWIG-001, FR-CHR-001 |
| `View/AdminPages.php` | Admin page view models (UiKit) | FR-UI-003, 004: FR-UIC-002 |
| `Resources/public/css/nowo-http-log-admin.css` | Admin payload stylesheet | FR-UI-003, 004: FR-UIC-001 |

## Outbound capture (HttpClient)

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `DependencyInjection/Compiler/OutboundHttpClientPass.php` | Decorates every `http_client.client` service selected by `outbound.clients` / `outbound.exclude_clients` at `outbound.decoration_priority` | FR-OUT-001 |
| `HttpClient/LoggingHttpClient.php` | Decorator that records one row per logical call through an `AsyncResponse` passthru without touching options, chunks or the body | FR-OUT-001 |
| `HttpClient/OutboundCall.php` | State of one call; records exactly once at last chunk, fatal chunk, `cancel()` or destruct | FR-OUT-001 |
| `HttpClient/OutboundClientFactory.php` | Builds `LoggingHttpClient` instances for the pass and the wrapper | FR-OUT-001, FR-OUT-004 |
| `HttpClient/HttpLogHttpClientWrapper.php` | Public service that wraps clients built in code (`HttpClient::create()`, SDK clients); returns the client unchanged when capture is off | FR-OUT-004 |
| `HttpClient/OutboundCapturePolicy.php` | Provider resolution (per-request, wrapped, client id, exact host, wildcard host, `other`) and keep rule (errors always, successes by `sampling_rate`) | FR-OUT-002 |
| `HttpClient/OutboundTarget.php` | Provider, scheme, host and path template one row stores | FR-OUT-002 |
| `HttpClient/OutboundUrlTemplater.php` | Pure URL to template: drops query, fragment and user info, lower-cases host, templates path segments | FR-OUT-002 |
| `HttpClient/OutboundErrorClassifier.php` | Error kind from exception class and transport text; the text is never stored | FR-OUT-003 |
| `Enum/OutboundErrorKind.php` | `error_kind` values (timeout, dns, connect, tls, transport, redirect_limit, cancelled, http_4xx, http_5xx, exception) | FR-OUT-003 |

## Mailer capture

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Mailer/MailerCaptureListener.php` | One `mailer` row per send attempt from transport events; never reads envelope, recipients, subject or body | FR-MAIL-001 |
| `Mailer/MailerTransportParser.php` | Scheme and host of a transport name; credentials never reach a row | FR-MAIL-001 |

## Named inbound channels

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Inbound/InboundChannel.php` | One `channels` entry: path / route include and exclude lists and what its rows keep | FR-CHAN-001 |
| `Inbound/InboundChannelMatcher.php` | First channel in config order that matches a request | FR-CHAN-001 |
| `Inbound/InboundRequestRecorder.php` | Allowlist row of a named-channel request (route template, status, duration, IP and user per config, guarded context) and web request counting for the rollup | FR-CHAN-002, FR-ROLL-001 |
| `Inbound/InboundRequestSubscriber.php` | Resolves the request context before the controller, pushes the tenant frame, records at `kernel.terminate` | FR-CHAN-002, FR-CTX-001 |
| `Inbound/RouteTemplateMap.php` | Route name to path template map, cached under `kernel.cache_dir`; unknown routes give `{unrouted}` | FR-CHAN-003 |
| `Inbound/RouteTemplateCacheWarmer.php` | Optional cache warmer that writes the map at `cache:warmup` | FR-CHAN-003 |
| `Inbound/ClientIpTruncator.php` | Keeps, truncates (IPv4 /24, IPv6 /48) or drops the client IP of a channel row | FR-CHAN-004 |
| `Inbound/HttpLogRequestContext.php` | Credential kind and id, tenant key and project key a resolver returns (opaque keys) | FR-CHAN-005 |
| `Inbound/HttpLogRequestContextResolution.php` | Runs the resolver hooks and drops any value failing the opaque-key rule or leaking a request credential | FR-CHAN-005 |
| `Inbound/HttpRequestContextResolverInterface.php` | Framework-free host hook (tag `nowo_http_log.request_context_resolver`) | FR-CHAN-005, FR-CHAN-006 |
| `Inbound/HttpLogRequestContextResolverInterface.php` | Deprecated Symfony-typed host hook, removed in 2.0 | FR-CHAN-005, FR-CHAN-006 |
| `Inbound/HttpRequestContext.php` | Framework-free request view (allowlisted headers and attributes) given to resolvers | FR-CHAN-006 |
| `Inbound/HttpRequestContextAttributes.php` | WeakMap holding raw allowlisted attribute values outside the object so dumps cannot reach them | FR-CHAN-006 |
| `Inbound/HttpRequestContextFactory.php` | Builds the `HttpRequestContext` from a request and token; drops secret-looking header and attribute names | FR-CHAN-006 |
| `DependencyInjection/Compiler/DeprecatedRequestContextResolverPass.php` | Reports once per container build each resolver implementing only the deprecated interface | FR-CHAN-005 |

## Tenant context & Messenger propagation

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Context/HttpLogContext.php` | Frame stack (`run()`, `runFrame()`) outbound calls and mails read tenant, project and message id from; emptied on `kernel.reset` | FR-CTX-001 |
| `Context/HttpLogContextInterface.php` | Port for the context (public alias of `HttpLogContext`) | FR-CTX-001 |
| `Context/HttpLogFrame.php` | One frame: tenant, project and Messenger message id | FR-CTX-001 |
| `Messenger/HttpLogContextMiddleware.php` | Adds the stamp on dispatch and pushes a frame while a message is handled | FR-CTX-002 |
| `Messenger/HttpLogContextStamp.php` | Stable message id and propagated tenant / project | FR-CTX-002 |

## Usage capture & buffering

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Usage/UsageCapture.php` | One allowlisted call (or count-only event); every value passes the field guard | FR-USE-001 |
| `Usage/UsageCall.php` | One per-call row of a usage channel | FR-USE-001 |
| `Usage/UsageFieldGuard.php` | Charset and length rules of every stored usage column; invalid values become NULL, never truncated | FR-USE-001 |
| `Usage/HttpLogChannel.php` | Channel names of the `channel` column (`web`, `outbound`, `mailer`, named) | FR-USE-001 |
| `Usage/HttpLogBuffer.php` | Queue flushed at request, command or message boundary; suspends outbound capture while writing | FR-USE-002 |
| `Usage/UsageBufferFlushSubscriber.php` | Flushes the buffer on `kernel.terminate`, `console.terminate` and after each handled or failed message | FR-USE-002 |
| `Usage/UsageCaptureSink.php` | Sends a flushed capture to Messenger (`async`) or to a direct DBAL insert | FR-USE-002 |
| `Usage/UsageEntryWriter.php` | DBAL `INSERT` of usage rows on the entry's connection, with the rollup in one transaction; never `persist()` / `flush()` | FR-USE-002, FR-ROLL-002 |
| `Event/HttpLogEntryRecordedEvent.php` | Event after a committed web or usage row; ids and scalars only | FR-EVT-001 |

## Usage read API

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Usage/HttpLogUsageReaderInterface.php` | Read port: `daily()`, `iterateDaily()`, `calls()`, `lastUsed()` | FR-USE-003 |
| `Usage/HttpLogUsageReader.php` | DBAL implementation over the rollup and entry tables | FR-USE-003 |
| `Usage/UsageScope.php` | Tenant or platform scope; no default | FR-USE-003 |
| `Usage/UsageQuery.php` | Immutable query on the daily rollup | FR-USE-003 |
| `Usage/CallQuery.php` | Immutable query on per-call rows of usage channels | FR-USE-003 |
| `Usage/CallPage.php` | One page of calls, newest first, with `nextCursor` | FR-USE-003 |
| `Usage/DailyUsage.php` | One result row of the rollup grouping, with average and p95 upper bound | FR-USE-003 |
| `Usage/CredentialUsage.php` | "Last used" of one credential: calls and errors in the last 30 UTC days | FR-USE-003 |

## Daily rollup

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Rollup/RollupTables.php` | Names and DBAL schema of the `daily` and `event_seen` tables | FR-ROLL-001 |
| `Rollup/RollupSchemaListener.php` | Adds the rollup tables to the entry EntityManager's schema, only while `rollup.enabled` | FR-ROLL-001 |
| `Rollup/RollupBucket.php` | Column values of one bucket, UTC days, duration histogram column | FR-ROLL-002 |
| `Rollup/RollupSql.php` | Platform upsert (MySQL / MariaDB, PostgreSQL, SQLite) with bound parameters | FR-ROLL-002 |
| `Rollup/UsageRollupStore.php` | Exactly-once writes, purge of buckets and ledger, erasure folds | FR-ROLL-002 |
| `Command/RollupBackfillCommand.php` | Builds buckets from per-call rows for days with none, never today, never before the cutoff | FR-ROLL-003 |

## Privacy (GDPR)

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Privacy/HttpLogPersonalDataSubject.php` | Whose entries are exported or erased: a user identifier or a client IP, compared exactly | FR-PRV-001 |
| `Privacy/HttpLogPersonalDataExporterInterface.php` | Export port (art. 15 / 20) and client IP windows | FR-PRV-001 |
| `Privacy/HttpLogPersonalDataExporter.php` | Keyset scan with exact `===` re-check, bounded by `export_limit` | FR-PRV-001 |
| `Privacy/HttpLogClientIpWindow.php` | One IP a user was logged from with first and last seen time | FR-PRV-001 |
| `Privacy/HttpLogPersonalDataErasureInterface.php` | Erasure port (art. 17) with transaction rules | FR-PRV-002 |
| `Privacy/HttpLogPersonalDataErasure.php` | Default erasure: delegates to the eraser, dispatches the event after its own commit | FR-PRV-002 |
| `Privacy/HttpLogErasureResult.php` | Rows deleted per kind | FR-PRV-002 |
| `Privacy/HttpLogPersonalDataErasedEvent.php` | Event after the erasure transaction committed | FR-PRV-002 |
| `Privacy/HttpLogErasedSubjects.php` | Request-scoped skip list so captures at `kernel.terminate` do not re-write an erased subject | FR-PRV-003 |
| `Privacy/HttpLogUsageEraserInterface.php` | Erasure of usage data by credential and by tenant | FR-PRV-004 |
| `Privacy/HttpLogUsageEraser.php` | Default usage eraser over rows and buckets in batches | FR-PRV-004 |
| `Privacy/HttpLogPersonalDataSubjectResolverInterface.php` | SPI: which subjects belong to a user being erased or exported | FR-PRV-005 |
| `Privacy/UserIdentifierSubjectResolver.php` | Default resolver: original identifier, else current `getUserIdentifier()` | FR-PRV-005 |
| `Privacy/UserKit/HttpLogUserKitExportProvider.php` | UserKit export section `http_requests` | FR-PRV-005 |
| `Privacy/UserKit/HttpLogUserKitPersonalDataEraser.php` | UserKit eraser `http_log` (priority 100) | FR-PRV-005 |

## Database exactness & optional mappings

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Doctrine/ExactCollation.php` | Binary NO PAD collation per platform and server version | FR-DB-001 |
| `Doctrine/ExactColumnsCollationListener.php` | Applies the exact collation to identifier columns of the entry table on MySQL / MariaDB | FR-DB-001 |
| `Doctrine/PathPrefixColumnListener.php` | Maps `path_prefix` and its index with `web_ui.filter_match: prefix` | FR-DB-002 |
| `Doctrine/UsageColumnsListener.php` | Maps the usage columns and indexes only when a usage channel is enabled | FR-DB-003 |

## Admin list, export storage & access

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Model/HttpLogListRow.php` | Scalar projection of one admin list row | FR-ADM-002 |
| `Model/HttpLogListCursor.php` | Keyset position of a list page carried as `?cursor=` | FR-ADM-002 |
| `Service/ExportFileStore.php` | Private (0700 / 0600) storage of background exports, visible per channel, deleted after `export.ttl_hours` | FR-EXP-002 |
| `Security/HttpLogChannelAccessCheckerInterface.php` | Optional capability: channels a user may see in list, entry, export and purge | FR-UI-004 |
| `DependencyInjection/Compiler/CsrfProtectionPass.php` | Fails the container build when the admin UI is on without form CSRF protection | FR-SEC-001 |

## Coverage summary

| Category | Mapped |
| --- | ---: |
| Bundle entry | 1 |
| DependencyInjection | 4 |
| Entity & persistence | 5 |
| Domain model & enums | 5 |
| Services | 6 |
| HTTP & events | 2 |
| Forms | 5 |
| Security | 2 |
| Messenger | 6 |
| CLI | 2 |
| Symfony config (`src/Resources/config/`) | 3 |
| Translations (`src/Resources/translations/`) | 7 |
| Twig views (`src/Resources/views/`) | 5 |
| Outbound capture (HttpClient) | 10 |
| Mailer capture | 2 |
| Named inbound channels | 15 |
| Tenant context & Messenger propagation | 5 |
| Usage capture & buffering | 9 |
| Usage read API | 8 |
| Daily rollup | 6 |
| Privacy (GDPR) | 15 |
| Database exactness & optional mappings | 4 |
| Admin list, export storage & access | 5 |
| **Total** | **132** |
