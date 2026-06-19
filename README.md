<div align="center">

![Extension icon](Resources/Public/Icons/Extension.png)

# TYPO3 extension `typo3_ai_mate`

[![Packagist](https://img.shields.io/packagist/v/konradmichalik/typo3-ai-mate?label=version&logo=packagist)](https://packagist.org/packages/konradmichalik/typo3-ai-mate)
[![Packagist Downloads](https://img.shields.io/packagist/dt/konradmichalik/typo3-ai-mate?color=brightgreen)](https://packagist.org/packages/konradmichalik/typo3-ai-mate)
[![Supported PHP Versions](https://img.shields.io/packagist/dependency-v/konradmichalik/typo3-ai-mate/php?logo=php)](https://packagist.org/packages/konradmichalik/typo3-ai-mate)
[![CGL](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-ai-mate/cgl.yml?label=cgl&logo=github)](https://github.com/konradmichalik/typo3-ai-mate/actions/workflows/cgl.yml)
[![Coverage](https://img.shields.io/coverallsCoverage/github/konradmichalik/typo3-ai-mate?logo=coveralls)](https://coveralls.io/github/konradmichalik/typo3-ai-mate)
[![Tests](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-ai-mate/tests.yml?label=tests&logo=github)](https://github.com/konradmichalik/typo3-ai-mate/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE.md)

</div>

A _dev-only_ TYPO3 introspection bridge for AI coding assistants. It exposes the **resolved runtime state** of an installation — TCA, page composition, resolved TypoScript, the PSR-15 middleware order, logs and per-request profiles — to assistants like Claude Code, Cursor or Copilot over [**MCP**](https://modelcontextprotocol.io/), so they reason from facts instead of guessing from source files.

> [!WARNING]
> This package is in early development stage and may change significantly in the future. I am working steadily to release a stable version as soon as possible.

> [!IMPORTANT]
> This package is **active only in a Development context** (`Environment::getContext()->isDevelopment()`).

## 🤔 Why

AI assistants normally read your raw source and config files and _guess_ at the result. But the state that actually matters — the merged TCA, the resolved TypoScript of a page, the real PSR-15 middleware order, whether a request was cached — is computed at runtime and can't be reliably inferred from files alone.

`typo3-ai-mate` hands the assistant that already-resolved state instead — see [Tools](#tools) below for the full list of what it exposes. This is often more token-efficient too: a compact resolved summary costs far fewer tokens than having the assistant read and reason over the raw source and config files.

### Use cases

- **[Slow page](docs/USE-CASES.md#slow-page)** — _"This page is slow — find the performance problem."_ The assistant reads the profile, spots N+1 queries / cache state / timing, and diagnoses instead of guessing.
- **[Error page](docs/USE-CASES.md#error-page)** — locate an exception in the logs and tie it back to the page that produced it.
- **[Major upgrade](docs/USE-CASES.md#major-upgrade-any-lts-jump-eg-v13--v14)** — surface breaking code, outstanding migrations and runtime deprecations before a major jump.

## 🔥 Installation

### Requirements

* TYPO3 13.4 LTS & 14.3 LTS
* PHP 8.2+
* Composer mode

### Composer

```bash
composer require --dev konradmichalik/typo3-ai-mate
```

> [!NOTE]
> Requiring `typo3-ai-mate` automatically pulls in `symfony/ai-mate` (the MCP server and `mate` binary) and [`konradmichalik/typo3-request-profiler`](https://packagist.org/packages/konradmichalik/typo3-request-profiler) (the profile source for the `typo3-profiler-*` tools) — no separate installs needed.

## 🔌 Connect your assistant

Scaffold the Mate workspace and register the tools once:

```bash
vendor/bin/mate init       # scaffold mate/ + mcp.json (skip if already present)
vendor/bin/mate discover   # register the typo3-* tools
```

`mate serve` is a single MCP server exposing all `typo3-*` tools — your assistant (Claude Code, Cursor, Copilot, …) starts it for you once it is registered:

```bash
claude mcp add typo3-ai-mate --scope project -- ddev exec vendor/bin/mate serve   # DDEV project
claude mcp add typo3-ai-mate --scope project -- ./vendor/bin/mate serve           # host PHP project
```

> [!NOTE]
> After updating the package (`composer update`), **reconnect the MCP server** so the assistant picks up new or changed tool schemas — in Claude Code run `/mcp` and reconnect `typo3-ai-mate`. Freshly installed vendor code alone is not enough; without a reconnect the assistant keeps using the previously registered tool definitions.

## ⚙️ How it works

The MCP tools run in the **Mate process** (its own Symfony DI container, `Configuration/Mate.php`). They boot no TYPO3; they reach it by shelling out to `vendor/bin/typo3 <command>` (`TYPO3_CONTEXT=Development`, stdout→JSON) via the `Typo3CliRunner` service, or by reading profile artifacts directly. The console commands run in the **TYPO3 process** (TYPO3 DI, `Configuration/Services.yaml`) and emit raw JSON.

```mermaid
flowchart LR
    A["AI agent (e.g. Claude)"] -->|MCP| B["Mate process (typo3-* tools)"]
    B -->|shell out| C["TYPO3 process (vendor/bin/typo3)"]
    C -->|JSON| B
```

### Tools

| Area | MCP tool | Purpose |
|---|---|---|
| Profiling | `typo3-profiler-latest` / `-list` / `-search` / `-get` | Inspect recorded per-request profiles as compact summaries (timing, N+1, cache, `page.id`), each linking a `typo3-profiler://profile/{token}` resource for the full SQL/section detail. |
| Page | `typo3-page` | Show a page's composition: content elements, cache signals and `USER_INT` plugins. |
| Logs | `typo3-logs-search` / `-tail` / `-by-level` | Search, tail or filter the TYPO3 logs. Returns a compact summary (distinct messages with occurrence counts and `lastSeen`, no stack traces) by default; pass `mode=full` for individual entries with truncated traces, and `since` (e.g. `1h`, `2d`) to scope to recent entries. |
| TCA | `typo3-tca` | Dump the resolved (merged, trimmed) TCA of a table. |
| TypoScript | `typo3-typoscript` | Dump the resolved frontend TypoScript of a page. |
| Middlewares | `typo3-middlewares` | List the resolved PSR-15 middleware order. |
| Events | `typo3-events` | List the resolved PSR-14 event listener registry. |
| Upgrade | `typo3-upgrade-wizards` | List pending and completed upgrade wizards — outstanding DB/config migrations. |
| Extension scanner | `typo3-extension-scanner` | Statically scan an extension — or all non-core extensions — against the core breaking/deprecation matchers. Returns a compact summary by default (matches grouped by message with strong/weak counts and the affected files, plus a per-origin rollup when scanning all); pass `mode=full` for individual matches with line content, and `ownCode=true` to skip third-party (vendor) packages. |
| Deprecations | `typo3-deprecations` | Report runtime deprecation notices, deduplicated and counted. Each one carries `origins` — the likely caller in own code. With deprecation logging enabled, a dev-only log processor records the caller's backtrace at log time for a high-confidence file:line; otherwise it falls back to a class-aware static reverse search across own PHP/Fluid files. |
| Rendering | `typo3-render-page` | Render a frontend page via an internal HTTP request (no external curl/Playwright) so runtime notices fire, and report the HTTP status plus the log entries written during that request. Requires a running webserver (e.g. DDEV). |

## 💡 Development

Custom `typo3-*` tools, the `Typo3CliRunner` recipe and security notes live in [`DEVELOPMENT.md`](DEVELOPMENT.md).

## 🔗 Related

[`hauptsacheNet/typo3-mcp-server`](https://github.com/hauptsacheNet/typo3-mcp-server) is a complementary project with a different goal: it gives assistants a native MCP server to **create, edit and translate TYPO3 content**, safely gated behind workspaces. `typo3-ai-mate` deliberately does **not** write anything — it is a dev-only, read-only **diagnostics** bridge for the resolved runtime state (performance, TypoScript, middlewares, logs). Use the former to edit content, the latter to debug it; they sit happily side by side.

## 🧑‍💻 Contributing

Please have a look at [`CONTRIBUTING.md`](CONTRIBUTING.md).

## ⭐ License

This project is licensed under [GNU General Public License 2.0 (or later)](LICENSE.md).
