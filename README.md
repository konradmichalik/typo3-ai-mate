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

It is both a **TYPO3 extension** (it ships console commands that boot _inside_ TYPO3) and a [**symfony/ai-mate**](https://symfony.com/doc/current/ai/components/mate.html) extension (it ships `#[McpTool]`s that run in the Mate server process and wrap those commands / read profile artifacts).

> [!IMPORTANT]
> This package is **active only in a Development context** (`Environment::getContext()->isDevelopment()`).

## 🤔 Why

AI assistants normally read your raw source and config files and _guess_ at the result. But the state that actually matters — the merged TCA, the resolved TypoScript of a page, the real PSR-15 middleware order, whether a request was cached — is computed at runtime and can't be reliably inferred from files alone.

`typo3-ai-mate` hands the assistant that already-resolved state instead — see [Tools](#tools) below for the full list of what it exposes.

### Use cases

- **Slow page** — _"This page is slow — find the performance problem."_ The assistant reads the profile, spots N+1 queries / cache state / timing, and diagnoses instead of guessing.
- **Error page** — locate an exception in the logs and tie it back to the page that produced it.
- **Major upgrade** — surface breaking code, outstanding migrations and runtime deprecations before a major jump.

See [`docs/USE-CASES.md`](docs/USE-CASES.md) for the full tool-by-tool flows and the `request_id` correlation anchor.

## 🔥 Installation

### Requirements

* TYPO3 13.4 LTS & 14.0+
* PHP 8.2+
* Composer mode

### Supports

| **Version** | **TYPO3** | **PHP** |
|-------------|-----------|---------|
| 0.x         | 13-14     | 8.2-8.5 |

### Composer

```bash
composer require --dev konradmichalik/typo3-ai-mate
```

> [!NOTE]
> Requiring `typo3-ai-mate` automatically pulls in `symfony/ai-mate` (the MCP server and `mate` binary) and [`konradmichalik/typo3-ai-mate`](https://packagist.org/packages/konradmichalik/typo3-ai-mate) (the profile source for the `typo3-profiler-*` tools) — no separate installs needed.

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

> [!TIP]
> Use `ddev exec` (not the `ddev <version>` wrapper — its header line would corrupt the stdio MCP stream). Verify with `claude mcp list` or `/mcp`. For Claude Desktop or other clients, add the same command to their `mcpServers` config.

## ⚙️ How it works

The MCP tools run in the **Mate process** (its own Symfony DI container, `Configuration/Mate.php`). They boot no TYPO3; they reach it by shelling out to `vendor/bin/typo3 <command>` (`TYPO3_CONTEXT=Development`, stdout→JSON) via the `Typo3CliRunner` service, or by reading profile artifacts directly. The console commands run in the **TYPO3 process** (TYPO3 DI, `Configuration/Services.yaml`) and emit raw JSON.

### Tools

| MCP tool | Purpose |
|---|---|
| `typo3-profiler-latest` / `-list` / `-search` / `-get` | Inspect recorded per-request profiles as compact summaries (timing, N+1, cache, `page.id`), each linking a `typo3-profiler://profile/{token}` resource for the full SQL/section detail. |
| `typo3-page` | Show a page's composition: content elements, cache signals and `USER_INT` plugins. |
| `typo3-logs-search` / `-tail` / `-by-level` | Search, tail or filter the TYPO3 logs, with exceptions extracted. |
| `typo3-tca` | Dump the resolved (merged, trimmed) TCA of a table. |
| `typo3-typoscript` | Dump the resolved frontend TypoScript of a page. |
| `typo3-middlewares` | List the resolved PSR-15 middleware order. |
| `typo3-events` | List the resolved PSR-14 event listener registry. |
| `typo3-upgrade-wizards` | List pending and completed upgrade wizards — outstanding DB/config migrations. |
| `typo3-extension-scanner` | Statically scan an extension — or all non-core extensions — against the core breaking/deprecation matchers. Returns a compact summary by default (matches grouped by message with strong/weak counts and the affected files, plus a per-origin rollup when scanning all); pass `mode=full` for individual matches with line content, and `ownCode=true` to skip third-party (vendor) packages. |
| `typo3-deprecations` | Report runtime deprecation notices, deduplicated and counted. |

> [!NOTE]
> The profiler tools (`typo3-profiler-*`) read profiles recorded by the bundled `typo3-ai-mate`. Trigger a frontend request in the Development context to produce `var/log/profiles/*.json`.

## 💡 Development

Custom `typo3-*` tools, the `Typo3CliRunner` recipe and security notes live in [`DEVELOPMENT.md`](DEVELOPMENT.md).

## 🔗 Related

[`hauptsacheNet/typo3-mcp-server`](https://github.com/hauptsacheNet/typo3-mcp-server) is a complementary project with a different goal: it gives assistants a native MCP server to **create, edit and translate TYPO3 content**, safely gated behind workspaces. `typo3-ai-mate` deliberately does **not** write anything — it is a dev-only, read-only **diagnostics** bridge for the resolved runtime state (performance, TypoScript, middlewares, logs). Use the former to edit content, the latter to debug it; they sit happily side by side.

## 🧑‍💻 Contributing

Please have a look at [`CONTRIBUTING.md`](CONTRIBUTING.md).

## ⭐ License

This project is licensed under [GNU General Public License 2.0 (or later)](LICENSE.md).
