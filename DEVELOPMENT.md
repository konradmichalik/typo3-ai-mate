# Development

## Adding your own tool

[ai-mate](https://symfony.com/doc/current/ai/components/mate.html) provides two native ways, both able to reuse the public `Typo3CliRunner` service (same error handling, `TYPO3_CONTEXT=Development`, JSON parsing):

- **A) Project-local** — a `#[MateTool]` class in `mate/src` (`App\Mate\`) + `composer dump-autoload`.
- **B) Reusable** — a Composer package with `extra.ai-mate` + `mate discover`.

```php
use KonradMichalik\Typo3AiMate\Mate\{ToolResult, Typo3CliRunner};
use Symfony\AI\Mate\Attribute\MateTool;

final class MyCustomTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    #[MateTool(name: 'typo3-my-thing', description: '…')]
    public function run(int $pageId): string
    {
        return ToolResult::untrusted($this->typo3->json('myext:something', [$pageId]));
    }
}
```

Recipe: (1) a TYPO3 console command that prints **raw JSON** (no `SymfonyStyle` — it decorates the output and breaks parsing), (2) a `#[MateTool]` class injecting `Typo3CliRunner`, returning its response through `ToolResult::from()`/`::untrusted()` (see [Untrusted data](#untrusted-data) below for which one), (3) register via A or B.

A method's parameters (plus their `@param` docblock) are reflected into the tool's input schema, so document them there — a `#[MateTool]` argument itself is a PHP compile-time constant (no `outputSchema`, `annotations` or runtime-computed text; see [Tool descriptions are static](#tool-descriptions-are-static) below).

## Tool surface

Growth of the tool surface was measured, not estimated, when the question first came up (issue #71): the tool definitions (attribute description, docblocks, parameter signatures) plus `INSTRUCTIONS.md` landed at roughly 6,000–8,000 tokens per session, near four percent of a 200k context window. `typo3-records` and `typo3-logs-search` were the two heaviest single definitions before a trim removed sentences that only restated what their own parameter docblocks already said — the client receives both, so repeating it in the top-level description was pure overhead, not extra information. That measurement predates the migration to ai-mate v0.13's native `#[MateTool]` attribute, which dropped `outputSchema`/`annotations` entirely (no home for them anymore) — re-measure before quoting the figure again, it can only have gone down.

**Conclusion: context size is not a reason to remove tools.** Four percent of the window does not justify deleting working functionality. Merging tools does not delete their descriptions either — it relocates them into parameter documentation, saving perhaps 30–40 percent of a merged block, not 75 percent ("four tools become one, so a quarter of the cost" does not hold up). If context were the actual goal, the lever is prose discipline, as applied above to `typo3-records`/`typo3-logs-search`, not tool count.

**The actual problem is routing, not size.** Seven tools carry the `profiler` prefix and three carry `logs` — ten entries for two concepts, with near-synonymous names inside each cluster, most notably the four profiler read tools (`typo3-profiler-latest`/`-list`/`-search`/`-get`). An assistant given "this page is slow" has to disambiguate between them without help from the names alone. The other tools are each a distinct, self-explanatory concept; the marginal cost of adding another one there (`typo3-changelog-search`, `typo3-site`, `typo3-db-schema`, …) is close to zero because it competes with nothing.

**Decision: disambiguate, don't consolidate.** Every tool in `PerformanceTool`, `ProfilerControlTool` and `LogsTool` now carries an explicit "use when" clause distinguishing it from its siblings, and `typo3-info` is documented as the entry point in `INSTRUCTIONS.md`'s "Start here" section. Consolidation is deferred, not ruled out: if routing quality still disappoints after this disambiguation pass, the profiler read quartet (`-latest`/`-list`/`-search`/`-get`) is the one genuine merge candidate, and it belongs in a 1.0 alongside other breaking changes, not a patch.

The claim that selection quality degrades past a specific tool count is a rule of thumb, not a measured property — treat the count as one input, not a threshold. Session context is also not this package's alone; a user with several Mate extensions (or other tools) installed can pass 40k tokens of instructions before typing anything. At 6,000–8,000 tokens this package is a reasonable citizen, and the lever for staying one is description discipline, not tool removal.

## Tool descriptions are static

A `#[MateTool(description: …)]` argument must be a PHP compile-time constant, and unlike mcp/sdk's old `#[McpTool]`, ai-mate v0.13's discovery classes (`ReflectionDiscoverer`, `CapabilityRegistry`) are `final` with no interface — there is no seam left to decorate discovery and splice runtime state into a description or hide a tool from the list, the way `Mate\DescriptionAwareDiscoverer` (removed) used to for the profiler/logs clusters.

State that used to live in a description suffix (e.g. "no profiles exist yet") now lives only in two places:

- **`typo3-info`'s `toolClusters`** (`Command\InfoCommand::describeToolClusters()`, backed by `Support\ToolClusterGate`/`Support\RuntimeArtifacts`) reports whether the profiler/logs clusters currently have anything to report, evaluated fresh on every call. Purely advisory — every tool stays registered and callable regardless.
- **The tool's own runtime response.** Calling a tool with nothing behind it (e.g. `typo3-profiler-latest` before any profile exists) answers `ToolResult::from(['error' => '...'])`/`::untrusted(...)`, which `ToolResult` turns into `{"unsupported": true, "reason": "..."}` — an honest miss rather than a wasted guess.

If you add a tool whose usefulness depends on runtime state, follow this pattern: keep the `#[MateTool]` description static and general, and have the method itself report `unsupported`/`reason` when its precondition is not met.

## Security

> [!WARNING]
> All tools operate on the **local installation only** and must never be exposed over a network. [ai-mate](https://symfony.com/doc/current/ai/components/mate.html) redacts cookies, auth headers and secrets by default.

On top of that, `typo3-ai-mate` itself:

- **Redacts PII and secrets** (`Support\Redactor`) — emails, IPv4 addresses and `key=value`/`key: value` secrets (`password`, `token`, `api_key`, `authorization`, …) are stripped from logs, TypoScript/TSconfig dumps, resolved records and profiler data before they reach the AI client.
- **Restricts `typo3-render-page` to configured site hosts** — an absolute `--url` is rejected unless its host matches one of the installation's site bases, and redirects are not followed, closing an SSRF vector against internal/cloud-metadata endpoints.
- **Validates profile tokens** — `typo3-profiler-get` and the `typo3-profiler://profile/{token}` resource only accept alphanumeric tokens, rejecting path traversal before the file-based lookup.
- **Hardens CLI argument passing** — `Typo3CliRunner` inserts an end-of-options `--` separator before positional arguments, so a value that happens to start with `-`/`--` (e.g. a table or page id from the assistant) can never be parsed as an option of the target command.
- **Caps how long an agent can leave profiling on** — `typo3-profiler-start` rejects durations above 60 minutes (the profiler itself permits up to 7 days), so a looping agent cannot turn a dev machine into a permanently profiled one. Writes go through the profiler's `profiler:activate`/`:deactivate` commands, keeping atomic write and file permissions in the package that owns the state file.

> [!NOTE]
> Profiles carry request URLs, SQL, timings and configuration values. URLs and SQL are redacted as described above, but everything else is exposed as recorded — enabling the profiling tools makes that data readable by any assistant with access to this installation.

### Untrusted data

ai-mate v0.13 added `Symfony\AI\Mate\Encoding\ResponseEncoder::encodeUntrusted()`: it nests a payload under an `untrusted_data` key alongside a `_security_notice` telling the model to treat the content strictly as data, never as instructions — defense against prompt injection planted in application-controlled content (a page title, a log message, a stored SQL value) that an assistant later reads back.

`Classes/Mate/ToolResult.php` exposes both `from()` (plain `ResponseEncoder::encode()`) and `untrusted()` (`encodeUntrusted()`). Use `untrusted()` whenever the payload includes text or identifiers an editor or a third-party extension could have authored — record/page content, log messages, rendered markup, resolved TCA labels, class/service names discovered from installed code. Use `from()` only for output this package itself computed (control/status state, pure enums, counts) with nothing pulled from the inspected application's own data or third-party code. When unsure, prefer `untrusted()` — wrapping data that turned out to be harmless costs nothing, the reverse does not.
