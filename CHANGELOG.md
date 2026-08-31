# Changelog

All notable changes to this project are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). While the version is below 1.0.0 a minor bump may contain breaking changes, and this file marks them explicitly.

## [0.5.0] - 2026-08-31

### Changed

- **Breaking:** migrated to `symfony/ai-mate` 0.13, which removed the MCP server and the `mcp/sdk` dependency. Tools are no longer MCP tools. They are discovered from the native `#[MateTool]`, `#[MateResource]` and `#[MateResourceTemplate]` attributes and invoked as a plain CLI: `vendor/bin/mate tools:call <name> --<param>=<value>`.
- **Breaking:** `typo3-ai-mate:install` no longer writes an MCP server entry to `.mcp.json` or `opencode.json`, because the `mate serve` command it pointed at no longer exists. The options `--agent` and `--skip-mcp-json` are gone with it. The command is now a wrapper around `mate init` and `mate discover`, which write the agent instructions themselves.
- **Breaking:** tool responses that carry data captured from the inspected installation are now nested under an `untrusted_data` key alongside a `_security_notice`. This covers records, logs, TCA, profiler data, rendered pages, site and icon identifiers, extension keys and everything else read from installed code. Only `typo3-profiler-start`, `-stop` and `-status` answer unwrapped, since profiler activation state is the one thing this package computes entirely by itself. Anything parsing tool output has to unwrap one level.
- Tool descriptions are static again. `symfony/ai-mate` 0.13 offers no seam to rewrite them at discovery time, so the profiler and logs clusters are no longer suppressed when they have nothing to report. Every tool is always registered and always callable, and a cluster with nothing behind it answers `{"unsupported": true, "reason": "..."}` instead. `typo3-info` reports the current state under `toolClusters`.
- Widened `konradmichalik/typo3-request-profiler` to `^0.5 || ^0.6`, so an installation already on the profiler's 0.6 line can take this release.

### Removed

- The `ddev mcp-inspect` command and its MCP Inspector wrapper. There is no protocol left to inspect. `ddev mcp-smoke` is replaced by `ddev mate-smoke`, which sweeps every tool through `mate tools:call` and additionally asserts the SSRF guard, profile-token validation, PII redaction and the untrusted-data envelope.
- `outputSchema` and `readOnlyHint`/`destructiveHint`/`openWorldHint` annotations on every tool. The native `#[MateTool]` attribute has no parameter to carry them. The read-only nature of the tool surface is unchanged and documented in the README instead.

### Fixed

- Tool handlers are registered so `mate` can resolve them. Declaring a tool class in `Configuration/Mate.php` replaced the public definition that `ContainerFactory` had already created, the compiler dropped it, and every call failed with "Handler ... is not registered as a service". This affected the package used as the root project, for instance when developing it; an installed extension loads in the opposite order and was not affected.

- Rewrote the tool reference as one section per tool with a table of contents and a short use case per tool, and added the six tools the old README table never listed (`typo3-info`, `typo3-site`, `typo3-commands`, `typo3-config`, `typo3-db-schema`, `typo3-changelog-search`).
- Restructured the documentation: the README is an entry point again, and reference material moved into `docs/` (`tools.md`, `connecting-an-assistant.md`, `how-it-works.md`, `security.md`, `extending.md`, `tool-surface.md`, `use-cases.md`). `DEVELOPMENT.md` is dissolved into those pages. `INSTRUCTIONS.md` stays at the root, since `mate discover` reads it from there.

### Upgrading from 0.4.0

1. Run `vendor/bin/typo3 typo3-ai-mate:install` again.
2. **Remove the stale `typo3-ai-mate` entry from `.mcp.json` and, if present, `opencode.json` by hand.** Nothing removes it for you, and it points at `mate serve`, which no longer exists. An assistant that still tries to start it will report a broken MCP server.
3. Make sure your assistant reads the instructions `mate init` writes. It maintains a managed block in `AGENTS.md` and a `CLAUDE.md` that imports it. An assistant reading only its own file, such as `.cursor/rules`, needs that import added by hand.

## [0.4.0] - 2026-08-25

### Added

- `typo3-flexform`, reconciling a record's stored FlexForm against the data structure currently valid for it, and reporting when it resolves to a different structure than expected.
- Argument-free question tools: `typo3-fluid-namespaces`, `typo3-icons` and `typo3-backend-modules`.
- The MCP server is registered per assistant harness, with `--agent=<claude|opencode|all>` and project autodetection.

### Changed

- A miss is an answer rather than an empty structure. A lookup that finds nothing reports `registered: false` or `found: false` with context, instead of an empty result that reads like a failed call.
- The profiler and logs tool clusters are only registered when they have something to report, and `typo3-info` reports which clusters are currently available and why.
- Tool output is budgeted, `typo3-tca` first, so a broad question no longer returns kilobytes that every later turn re-reads.

### Fixed

- `typo3-changelog-search` is callable again.
- Two defects found while auditing for the release.

## [0.3.0] - 2026-08-14

### Added

- `typo3-info` as the session entry point, reporting version, context, database, extensions and package versions.
- `typo3-changelog-search` for offline migration guidance from the installed core's own changelog.
- `typo3-site`, `typo3-config` (with secret masking), `typo3-db-schema` and `typo3-commands`.
- Tools for controlling request profiling within a bounded time window.
- `typo3-ai-mate:install` for one-command onboarding.
- Tool annotations on every tool, so a client can tell read-only diagnostics from the few tools that write.

### Changed

- **Breaking:** `typo3-tca` is built on the core Schema API rather than on raw TCA.
- Tool descriptions carry runtime state, so a precondition is visible before a call rather than only in its result.
- The profiler and logs clusters are disambiguated, each tool naming when to use it instead of its siblings.

## [0.2.0] - 2026-07-08

### Added

- `typo3-records`, a read-only structured record query.
- `typo3-tsconfig` and `typo3-fluid-resolve`.

### Changed

- Log parsing streams with bounded memory and capped traces.
- Deprecation origins read own-code files lazily; the extension scanner preloads matcher configs and reuses read content.

### Security

- Personal data and secrets are redacted from logs, TypoScript and resolved records, and the record query blocks session tables and secret-named columns.
- Console commands are blocked in the Production application context.
- `typo3-render-page` is restricted to the installation's configured site hosts, closing an SSRF vector.
- Profile tokens are validated against path traversal before the file-based lookup.

## [0.1.0] - 2026-06-19

Initial release.

[Unreleased]: https://github.com/konradmichalik/typo3-ai-mate/compare/0.5.0...HEAD
[0.5.0]: https://github.com/konradmichalik/typo3-ai-mate/compare/0.4.0...0.5.0
[0.4.0]: https://github.com/konradmichalik/typo3-ai-mate/compare/0.3.0...0.4.0
[0.3.0]: https://github.com/konradmichalik/typo3-ai-mate/compare/0.2.0...0.3.0
[0.2.0]: https://github.com/konradmichalik/typo3-ai-mate/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/konradmichalik/typo3-ai-mate/releases/tag/0.1.0
