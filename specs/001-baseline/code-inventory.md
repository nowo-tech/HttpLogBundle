# Code inventory — HttpLogBundle baseline

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/http-log-bundle`  
**Last audited**: 2026-08-03

Maps **100%** of production files under `src/` (43 units). Test and demo trees are out of Packagist scope unless promoted in the spec.

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

## Entity & persistence

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Entity/HttpLogEntry.php` | Doctrine entity | FR-ORM-001 |
| `Repository/HttpLogEntryRepository.php` | Query / pagination | FR-ORM-001, FR-ADM-001 |

## Domain model & enums

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Model/HttpLogCapture.php` | Capture DTO | FR-CAP-001 |
| `Enum/BodyContentType.php` | Content-type enum | FR-CAP-003 |
| `Enum/CssFramework.php` | Admin UI CSS stack | FR-UI-001 |
| `Enum/ExportFormat.php` | Export format enum | FR-EXP-001 |

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
| `EventSubscriber/HttpLogSubscriber.php` | Kernel capture on terminate | FR-CAP-001, FR-CAP-002 |
| `Controller/HttpLogAdminController.php` | Admin UI routes | FR-ADM-001, FR-UI-002 |

## Forms

| Source file | Purpose | Requirement IDs |
| --- | --- | --- |
| `Form/HttpLogFilterType.php` | Admin list filters | FR-ADM-001 |

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
| `Resources/views/layout.html.twig` | Admin layout shell | FR-TWIG-001, FR-UI-001 |
| `Resources/views/admin/index.html.twig` | List page | FR-ADM-001, FR-TWIG-001 |
| `Resources/views/admin/show.html.twig` | Detail page | FR-ADM-001, FR-TWIG-001 |
| `Resources/views/admin/_filter.html.twig` | Filter partial | FR-ADM-001, FR-TWIG-001 |

## Coverage summary

| Metric | Value |
| --- | --- |
| Production units mapped | 43 |
| Unmapped files under `src/` | 0 |
