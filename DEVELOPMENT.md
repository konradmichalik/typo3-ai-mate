# Development

## Adding your own tool

[ai-mate](https://symfony.com/doc/current/ai/components/mate.html) provides two native ways, both able to reuse the public `Typo3CliRunner` service (same error handling, `TYPO3_CONTEXT=Development`, JSON parsing):

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

## Runtime-computed tool descriptions

A `#[McpTool(description: …)]` argument must be a PHP compile-time constant, so it cannot read the filesystem itself. `typo3-profiler-latest/-list/-search/-get`, `typo3-profiler-start/-stop/-status` and `typo3-render-page` still need a state-dependent sentence (e.g. "no profiles exist yet, run typo3-profiler-start first") — the field an assistant reads *before* deciding to call a tool, so stating a precondition there prevents a wasted call instead of only explaining it in the result.

`Configuration/Mate.php` registers `Mate\DescriptionAwareDiscoverer` as a Symfony DI decorator of `Mcp\Capability\Discovery\DiscovererInterface`. It runs the SDK's real attribute discovery, then rewrites the description of those tools using `Mate\ToolDescriptionComputer`, which only reads `var/log/profiles`, `var/log/profiler-activation-state.json` and `config/sites/*/config.yaml` directly (the same boot-free approach as `ProfileProvider`/`ProfilerStateProvider`) — never a TYPO3 boot, since discovery runs before any tool call, on every `mate` CLI invocation.

`DiscovererInterface`/`DiscoveryState`/`ToolReference` are marked `@internal` by `mcp/sdk`; composer.json pins `mcp/sdk: ^0.7`, so re-verify `DescriptionAwareDiscoverer` against the SDK's discovery internals on every minor bump.

**Startup overhead**: `ai-mate` already rebuilds and compiles its DI container on every CLI invocation (no persisted container cache exists). Measured with `time vendor/bin/mate mcp:tools:list` (3 runs each, `TYPO3_CONTEXT=Development`): ~120–135ms with the decorator active vs. ~125–255ms without it on the same machine — the added filesystem reads are within run-to-run noise, not a measurable addition on top of the existing container-compile cost.

> [!NOTE]
> MCP clients capture tool descriptions at connection time. A state change mid-session (a profile just got recorded, profiling was just started) is not reflected until the client reconnects — the same caveat as the README's "reconnect after `composer update`" note.

## Security

> [!WARNING]
> All tools operate on the **local installation only** and must never be exposed over a network. [ai-mate](https://symfony.com/doc/current/ai/components/mate.html) redacts cookies, auth headers and secrets by default.

On top of that, `typo3-ai-mate` itself:

- **Redacts PII and secrets** (`Support\Redactor`) — emails, IPv4 addresses and `key=value`/`key: value` secrets (`password`, `token`, `api_key`, `authorization`, …) are stripped from logs, TypoScript/TSconfig dumps, resolved records and profiler data before they reach the AI client.
- **Restricts `typo3-render-page` to configured site hosts** — an absolute `--url` is rejected unless its host matches one of the installation's site bases, and redirects are not followed, closing an SSRF vector against internal/cloud-metadata endpoints.
- **Validates profile tokens** — `typo3-profiler-get` and the `typo3-profiler://profile/{token}` resource only accept alphanumeric tokens, rejecting path traversal before the file-based lookup.
- **Hardens CLI argument passing** — `Typo3CliRunner` inserts an end-of-options `--` separator before positional arguments, so a value that happens to start with `-`/`--` (e.g. a table or page id from the MCP client) can never be parsed as an option of the target command.
- **Caps how long an agent can leave profiling on** — `typo3-profiler-start` rejects durations above 60 minutes (the profiler itself permits up to 7 days), so a looping agent cannot turn a dev machine into a permanently profiled one. Writes go through the profiler's `profiler:activate`/`:deactivate` commands, keeping atomic write and file permissions in the package that owns the state file.

> [!NOTE]
> Profiles carry request URLs, SQL, timings and configuration values. URLs and SQL are redacted as described above, but everything else is exposed as recorded — enabling the profiling tools makes that data readable by any MCP client connected to the installation.
