# TYPO3 AI Mate

[![Tests](https://github.com/konradmichalik/typo3-ai-mate/actions/workflows/tests.yml/badge.svg)](https://github.com/konradmichalik/typo3-ai-mate/actions/workflows/tests.yml)
[![CGL](https://github.com/konradmichalik/typo3-ai-mate/actions/workflows/cgl.yml/badge.svg)](https://github.com/konradmichalik/typo3-ai-mate/actions/workflows/cgl.yml)

Dev-only TYPO3 introspection for AI coding assistants. This extension is both a
**TYPO3 extension** (it ships console commands that boot *inside* TYPO3) and a
[**symfony/ai-mate**](https://symfony.com/doc/current/ai/components/mate.html)
**extension** (it ships `#[McpTool]`s that run in the Mate server process and
wrap those commands / read profile artifacts).

It delivers the **resolved runtime state** of an installation — TCA, page
composition, resolved TypoScript, the PSR-15 middleware order, logs and per-request
profiles — to assistants like Claude Code, Cursor or Copilot over **MCP**, so the
assistant reasons from facts instead of guessing from source files.

> **Lead use case:** *"This page is slow, find the performance problem."* The
> assistant calls a profiler tool, sees the N+1 queries / cache state / timing,
> and diagnoses — instead of reading ten files and guessing.

## Requirements

- TYPO3 **v13.4** or **v14.0**
- PHP **8.2+**, Composer mode
- **Development** application context (`Environment::getContext()->isDevelopment()`)

## Installation

```bash
composer require --dev symfony/ai-mate
vendor/bin/mate init                                          # creates mate/, mate/src, mcp.json
composer require --dev konradmichalik/typo3-ai-mate           # MCP tools (extra.ai-mate)
composer require --dev konradmichalik/typo3-request-profiler  # profile artifacts (optional but recommended)
composer dump-autoload
vendor/bin/mate discover                                      # registers typo3-ai-mate in mate/extensions.php
vendor/bin/mate serve                                         # MCP server; the assistant binds via mcp.json
```

## How it works — two containers

The MCP tools run in the **Mate process** (its own Symfony DI container,
`Configuration/Mate.php`). They boot no TYPO3; they reach it by shelling out to
`vendor/bin/typo3 <command>` (`TYPO3_CONTEXT=Development`, stdout→JSON) via the
`Typo3CliRunner` service, or by reading profile artifacts directly. The console
commands run in the **TYPO3 process** (TYPO3 DI, `Configuration/Services.yaml`)
and emit raw JSON.

## Tools

| MCP tool | Wraps / reads | Purpose |
|---|---|---|
| `typo3-profiler-latest` / `-list` / `-search` / `-get` | `var/log/profiles/*.json` | request profiles (needs `typo3-request-profiler`) |
| `typo3-page` | `typo3-ai-mate:page:info` | page composition, cache signals, USER_INT plugins |
| `typo3-logs-search` / `-tail` / `-by-level` | `typo3-ai-mate:logs:search` | TYPO3 logs |
| `typo3-tca` | `typo3-ai-mate:tca:dump` | resolved (trimmed) TCA |
| `typo3-typoscript` | `typo3-ai-mate:typoscript:dump` | resolved frontend TypoScript |
| `typo3-middlewares` | `typo3-ai-mate:middlewares:list` | resolved PSR-15 order |

### Correlation anchor `request_id`

`request_id` (= profile `token` = `Core\RequestId`, also logged as `request="…"`)
links the profile, the page and the logs of one request — see `INSTRUCTIONS.md`.

## Adding your own tool

ai-mate provides two native ways, both able to reuse the public `Typo3CliRunner`
service (same error handling, `TYPO3_CONTEXT=Development`, JSON parsing):

- **A) Project-local** — a `#[McpTool]` class in `mate/src` (`App\Mate\`) +
  `composer dump-autoload`.
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

Recipe: (1) a TYPO3 console command that prints **raw JSON** (no `SymfonyStyle` —
it decorates the output and breaks parsing), (2) a `#[McpTool]` class injecting
`Typo3CliRunner`, (3) register via A or B.

## Security

ai-mate redacts cookies / auth headers / secrets by default. All tools are
dev-only and operate on the local installation.

## Development

See [`CONTRIBUTING.md`](CONTRIBUTING.md). Tests run via `composer test`; coding
guidelines, static analysis and rector live in `Tests/CGL` (`composer cgl …`).

## License

GPL-2.0-or-later. See [`LICENSE.md`](LICENSE.md).
