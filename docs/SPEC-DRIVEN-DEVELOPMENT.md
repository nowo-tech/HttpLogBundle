# Spec-driven development

## Table of contents

- [Three layers](#three-layers)
- [User stories](#user-stories)
- [Functional scope](#functional-scope)
- [Validating the spec](#validating-the-spec)
- [Requirement identifiers (`REQ-*`)](#requirement-identifiers-req)
- [Suggested workflow for contributors](#suggested-workflow-for-contributors)
- [GitHub Spec Kit (summary)](#github-spec-kit-summary)
- [See also](#see-also)

## Three layers

In this repository, **spec-driven development** has three layers that stay in sync:

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md)), initialized with [GitHub Spec Kit](https://github.com/github/spec-kit) (`.specify/`, **Cursor Agent** skills in `.cursor/skills/speckit-*`). The inventory maps **100%** of production code in `src/`. **How to install, initialize, and use Spec Kit:** [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Product behavior** — HTTP request/response capture, body policies, redaction, admin UI, export, and purge (see [USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md), [INSTALLATION.md](INSTALLATION.md)). **PHPUnit** and **PHPStan** enforce contracts in CI.
3. **Traceability anchors** — stable **`REQ-*`** identifiers in Makefiles, demos, and docs aligned with the Nowo bundle checklist.

There is no separate executable spec language (for example Gherkin); Spec Kit specs, tests, and static analysis are the mechanical proof alongside this document.

## User stories

| ID | Story |
| --- | --- |
| US-01 | As an operator I capture HTTP requests and responses on kernel terminate with configurable sampling and environment filters |
| US-02 | As a security officer I redact sensitive headers, query parameters, and JSON body keys before storage |
| US-03 | As an operator I store response bodies by content type (html, json, soap, xml, text, binary, other) with truncation |
| US-04 | As an admin I browse and filter captured entries in a secured web UI at `/admin/http-log` |
| US-05 | As an admin I export filtered logs to CSV or JSON (sync or async via Messenger) |
| US-06 | As an operator I purge old entries by retention policy or on demand |
| US-07 | As a maintainer I run the Symfony 8 FrankenPHP demo and CI smoke checks |

## Functional scope

**In scope:** kernel subscriber capture, Doctrine persistence, body policy by content type, redaction, admin UI with access checker, CLI export/purge, Messenger async handlers, Flex recipe, FrankenPHP demo.

**Non-goals (later):** distributed tracing correlation beyond `X-Request-Id`, real-time streaming dashboard, multi-tenant isolation.

## Validating the spec

```bash
make release-check
# or
make qa && make test-coverage-100
make demo-smoke
```

- PHPUnit: `tests/Unit`, `tests/Integration`
- Demo smoke: `make demo-smoke` (REQ-TEST-011)

## Requirement identifiers (`REQ-*`)

| ID | Where | What it marks |
| --- | --- | --- |
| REQ-DOCS-002 | `README.md` | Canonical documentation link order |
| REQ-UI-002 | `docs/SECURITY.md`, admin controller | Secured admin UI (`access_roles` / custom checker) |
| REQ-DEMO-005 | `demo/symfony8/Makefile` | `make up` prints demo URL from `PORT` |
| REQ-DEMO-007 | `demo/symfony8/Makefile` `link-bundle` | Demo syncs path-mounted bundle |
| REQ-TEST-011 | `Makefile` `demo-smoke`, `.github/workflows/demo-smoke.yml` | Boot demo and assert HTTP 200 |
| REQ-MAKE-002 | `Makefile` `release-check` | Pre-release QA chain |
| REQ-MAKE-004 | `Makefile` `validate-translations` | Translation key parity vs `en` |
| REQ-TWIG-001 | `TwigPathsPass` | Application Twig overrides win over bundle templates |
| REQ-GIT-001 | `.github/workflows/ci.yml` | No Cursor co-author trailers in git history |

## Suggested workflow for contributors

1. **Clarify behavior** in an issue or draft PR with acceptance criteria.
2. **Implement** with tests and static analysis.
3. **Anchor scripts and demos** when dev UX changes (`REQ-*` comments and this table).
4. **Ship integrator docs** when behavior or configuration changes: [USAGE.md](USAGE.md), [CONFIGURATION.md](CONFIGURATION.md), [CHANGELOG.md](CHANGELOG.md), [UPGRADING.md](UPGRADING.md).
5. **Keep Spec Kit artifacts in sync** when `src/` changes:
   - Update [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) and [`code-inventory.md`](../specs/001-baseline/code-inventory.md).
   - Follow the maintainer checklist in [`SPEC-KIT.md`](SPEC-KIT.md).

---

## GitHub Spec Kit (summary)

This repository uses [GitHub Spec Kit](https://github.com/github/spec-kit) with **Cursor Agent** (`cursor-agent` integration).

| Artifact | Path |
| --- | --- |
| **Operator manual** (install, init, usage) | [`SPEC-KIT.md`](SPEC-KIT.md) |
| Baseline spec | [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) |
| Code inventory (100%) | [`specs/001-baseline/code-inventory.md`](../specs/001-baseline/code-inventory.md) |
| Constitution | [`.specify/memory/constitution.md`](../.specify/memory/constitution.md) |
| Cursor Agent skills | [`.cursor/skills/`](../.cursor/skills/) (`speckit-*`) |

**Quick start (maintainers):**

```bash
specify init --here --force --integration cursor-agent --script sh
specify integration list
```

In Cursor Agent, start a new feature with `/speckit-specify <description>`. For tooling details, read **[`SPEC-KIT.md`](SPEC-KIT.md)**.

---

## See also

- [`SPEC-KIT.md`](SPEC-KIT.md) — GitHub Spec Kit manual
- [USAGE.md](USAGE.md)
- [CONFIGURATION.md](CONFIGURATION.md)
- [SECURITY.md](SECURITY.md)
- [CONTRIBUTING.md](CONTRIBUTING.md)
- [RELEASE.md](RELEASE.md)
- [ENGRAM.md](ENGRAM.md)
