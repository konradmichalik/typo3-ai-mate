<div align="center">

# TYPO3 extension `typo3_ai_mate`

[![Packagist](https://img.shields.io/packagist/v/konradmichalik/typo3-ai-mate?label=version&logo=packagist)](https://packagist.org/packages/konradmichalik/typo3-ai-mate)
[![Packagist Downloads](https://img.shields.io/packagist/dt/konradmichalik/typo3-ai-mate?color=brightgreen)](https://packagist.org/packages/konradmichalik/typo3-ai-mate)
[![Supported PHP Versions](https://img.shields.io/packagist/dependency-v/konradmichalik/typo3-ai-mate/php?logo=php)](https://packagist.org/packages/konradmichalik/typo3-ai-mate)
[![CGL](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-ai-mate/cgl.yml?label=cgl&logo=github)](https://github.com/konradmichalik/typo3-ai-mate/actions/workflows/cgl.yml)
[![Tests](https://img.shields.io/github/actions/workflow/status/konradmichalik/typo3-ai-mate/tests.yml?label=tests&logo=github)](https://github.com/konradmichalik/typo3-ai-mate/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE.md)

</div>

A _dev-only_ TYPO3 introspection bridge for AI coding assistants. It exposes the **resolved runtime state** of an installation — TCA, page composition, resolved TypoScript, the PSR-15 middleware order, logs and per-request profiles — to assistants like Claude Code, Cursor or Copilot over [**MCP**](https://modelcontextprotocol.io/), so they reason from facts instead of guessing from source files.

> [!IMPORTANT]
> This package is **active only in a Development context** (`Environment::getContext()->isDevelopment()`).

It is both a **TYPO3 extension** (it ships console commands that boot _inside_ TYPO3) and a [**symfony/ai-mate**](https://symfony.com/doc/current/ai/components/mate.html) extension (it ships `#[McpTool]`s that run in the Mate server process and wrap those commands / read profile artifacts).

> [!TIP]
> **Lead use case:** _"This page is slow — find the performance problem."_ The assistant calls a profiler tool, sees the N+1 queries / cache state / timing, and diagnoses — instead of reading ten files and guessing.

> [!WARNING]
> This package is in early development stage and may change significantly in the future. I am working steadily to release a stable version as soon as possible.

**What it exposes to the assistant:**

- Resolved (merged) TCA of any table
- Page composition — content elements, cache signals, `USER_INT` plugins
- Resolved frontend TypoScript of a page
- The resolved PSR-15 middleware order
- TYPO3 logs (search / tail / by level) with exception extraction
- Per-request profiles — SQL, N+1 patterns, cache state, timing

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
> Requiring `typo3-ai-mate` automatically pulls in `symfony/ai-mate` (the MCP server and `mate` binary) and [`konradmichalik/typo3-request-profiler`](https://packagist.org/packages/konradmichalik/typo3-request-profiler) (the profile source for the `typo3-profiler-*` tools) — no separate installs needed.

## 🔌 Connect your assistant

Scaffold the Mate workspace and register the tools once:

```bash
vendor/bin/mate init       # scaffold mate/ + mcp.json (skip if already present)
vendor/bin/mate discover   # register the typo3-* tools
```

`mate serve` is a single MCP server exposing all `typo3-*` tools — Claude starts it for you once it is registered:

```bash
claude mcp add typo3-ai-mate --scope project -- ddev exec vendor/bin/mate serve   # DDEV project
claude mcp add typo3-ai-mate --scope project -- ./vendor/bin/mate serve           # host PHP project
```

> [!TIP]
> Use `ddev exec` (not the `ddev <version>` wrapper — its header line would corrupt the stdio MCP stream). Verify with `claude mcp list` or `/mcp`. For Claude Desktop or other clients, add the same command to their `mcpServers` config.

## ⚙️ How it works

The MCP tools run in the **Mate process** (its own Symfony DI container, `Configuration/Mate.php`). They boot no TYPO3; they reach it by shelling out to `vendor/bin/typo3 <command>` (`TYPO3_CONTEXT=Development`, stdout→JSON) via the `Typo3CliRunner` service, or by reading profile artifacts directly. The console commands run in the **TYPO3 process** (TYPO3 DI, `Configuration/Services.yaml`) and emit raw JSON.

## ✨ Tools

| MCP tool | Wraps / reads | Purpose |
|---|---|---|
| `typo3-profiler-latest` / `-list` / `-search` / `-get` | `var/log/profiles/*.json` | request profiles (recorded by `typo3-request-profiler`) |
| `typo3-page` | `typo3-ai-mate:page:info` | page composition, cache signals, `USER_INT` plugins |
| `typo3-logs-search` / `-tail` / `-by-level` | `typo3-ai-mate:logs:search` | TYPO3 logs |
| `typo3-tca` | `typo3-ai-mate:tca:dump` | resolved (trimmed) TCA |
| `typo3-typoscript` | `typo3-ai-mate:typoscript:dump` | resolved frontend TypoScript |
| `typo3-middlewares` | `typo3-ai-mate:middlewares:list` | resolved PSR-15 order |

> [!NOTE]
> The profiler tools (`typo3-profiler-*`) read profiles recorded by the bundled `typo3-request-profiler`. Trigger a frontend request in the Development context to produce `var/log/profiles/*.json`.

### Diagnose flows

Two common assistant workflows the tools directly support:

- **Slow page** — `typo3-profiler-latest` → spot N+1 queries / uncached blocks → `typo3-page` for cache signals → correlate via `request_id`
- **Error page** — `typo3-logs-search` / `-by-level` → locate the exception → `typo3-page` for context → correlate via `request_id`

### Correlation anchor `request_id`

`request_id` (= profile `token` = `Core\RequestId`, also logged as `request="…"`) links the profile, the page and the logs of one request — see `INSTRUCTIONS.md`.

## 💡 Adding your own tool

ai-mate provides two native ways, both able to reuse the public `Typo3CliRunner` service (same error handling, `TYPO3_CONTEXT=Development`, JSON parsing):

- **A) Project-local** — a `#[McpTool]` class in `mate/src` (`App\Mate\`) + `composer dump-autoload`.
- **B) Reusable** — a Composer package with `extra.ai-mate` + `mate discover`.

```php
use KonradMichalik\Typo3AiMate\Mate\Typo3CliRunner;
use Mcp\Capability\Attribute\McpTool;

final class MyCustomTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    #[McpTool(name: 'typo3-my-thing', description: '…')]
    public function run(int $pageId): array
    {
        return $this->typo3->json('myext:something', [$pageId]);
    }
}
```

Recipe: (1) a TYPO3 console command that prints **raw JSON** (no `SymfonyStyle` — it decorates the output and breaks parsing), (2) a `#[McpTool]` class injecting `Typo3CliRunner`, (3) register via A or B.

## 🛡️ Security

> [!WARNING]
> All tools operate on the **local installation only** and must never be exposed over a network. ai-mate redacts cookies, auth headers and secrets by default.

## 🔗 Related

[`hauptsacheNet/typo3-mcp-server`](https://github.com/hauptsacheNet/typo3-mcp-server) is a complementary project with a different goal: it gives assistants a native MCP server to **create, edit and translate TYPO3 content**, safely gated behind workspaces. `typo3-ai-mate` deliberately does **not** write anything — it is a dev-only, read-only **diagnostics** bridge for the resolved runtime state (performance, TypoScript, middlewares, logs). Use the former to edit content, the latter to debug it; they sit happily side by side.

## 🧑‍💻 Contributing

Please have a look at [`CONTRIBUTING.md`](CONTRIBUTING.md).

## ⭐ License

This project is licensed under [GNU General Public License 2.0 (or later)](LICENSE.md).
