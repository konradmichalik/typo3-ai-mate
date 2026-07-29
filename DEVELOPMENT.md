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
