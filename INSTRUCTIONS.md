# TYPO3 AI Mate — tool instructions

These tools expose the **resolved runtime state** of a TYPO3 installation (dev
context only). Prefer them over reading source files: they report what TYPO3
actually computed, not what the code might do.

## Diagnose instead of guessing

**"This page is slow":**
1. `typo3-profiler-latest` — see timing, query count, `duplicate_queries` (N+1),
   `cache.hit`/`cacheable`, and the `page.id`.
2. `typo3-page` with that `page.id` — see the content elements and which plugins
   are `USER_INT` (uncached). Attribute the N+1 / cache miss to a concrete element.
3. Optionally `typo3-typoscript pageId=<id> path=...` to inspect the plugin's setup.

**"This page errors":**
1. `typo3-logs-search query="..."` (or `typo3-logs-by-level level=error`) — find
   the exception, class and stack trace.
2. Use the entry's `request_id` to fetch the matching profile
   (`typo3-profiler-get token=<request_id>`) and page (`typo3-page`).

## Correlation anchor: `request_id`

`request_id` (= profile `token` = TYPO3 `Core\RequestId`, logged as `request="…"`)
links every request-scoped tool:

```
request_id ──┬── typo3-profiler-*  (SQL, N+1, timing, page.id)
             ├── typo3-page        (content elements / USER_INT plugins)
             └── typo3-logs-*      (exception + stack trace)
```

## Tools

- `typo3-profiler-latest` / `-list` / `-search` / `-get` — request profiles
  (requires the `typo3-request-profiler` extension installed and a triggered FE
  request in the Development context).
- `typo3-page` — page composition + cache signals (expand a profile `page.id`).
- `typo3-logs-search` / `-tail` / `-by-level` — TYPO3 logs.
- `typo3-tca` — resolved (trimmed) TCA of a table, or all table names.
- `typo3-typoscript` — resolved frontend TypoScript of a page (scope with `path`).
- `typo3-middlewares` — resolved PSR-15 middleware order of a stack.
- `typo3-events` — resolved PSR-14 event listener registry (event => listeners).
- `typo3-upgrade-wizards` — all upgrade wizards (pending/done) with status; which
  DB/config migrations are still outstanding. Read-only.
- `typo3-extension-scanner` — static scan of an extension's PHP against the core
  breaking/deprecation matchers (`extension=<key>`); where *your* code breaks.
- `typo3-deprecations` — runtime deprecation notices, deduplicated and grouped by
  message with counts (`loggingEnabled` flag — see below).

## Planning a major upgrade (v13 → v14)

Combine the three upgrade tools — the same building blocks as the backend upgrade
module — to reason from the installation's real state instead of the changelog:

1. `typo3-extension-scanner extension=<key>` — static analysis: which lines in your
   own code break / are deprecated in the installed target version (`message`,
   `line`, `strong`/`weak` `indicator`). Biggest lever, runs headless.
2. `typo3-upgrade-wizards` — which DB/config migrations are still `AVAILABLE` vs
   `DONE`. Read-only; the assistant must not run wizards autonomously.
3. `typo3-deprecations` — what actually logged a deprecation at runtime,
   deduplicated by message. Complements (1). If `loggingEnabled` is `false`, the
   `deprecations` log channel is off (the default) — an empty list means "not
   measured", **not** "no deprecations". Enable
   `[LOG][TYPO3][CMS][deprecations][writerConfiguration]` to collect data.

## Adding your own TYPO3 tool

Two native ai-mate ways — both can reuse the shared, public `Typo3CliRunner`
service (it shells out to `vendor/bin/typo3`, sets `TYPO3_CONTEXT=Development`,
and parses stdout JSON):

**A) Project-local** — drop a `#[McpTool]` class into `mate/src` (`App\Mate\`),
then `composer dump-autoload`. For one-off, project-specific tools.

**B) Reusable** — ship a Composer package with `extra.ai-mate`, then
`vendor/bin/mate discover`. For cross-project tools.

Recipe: (1) a TYPO3 console command that prints **raw JSON**, (2) a `#[McpTool]`
class that injects `Typo3CliRunner` and wraps it:

```php
use KonradMichalik\Typo3AiMate\Mate\Typo3CliRunner;
use Mcp\Capability\Attribute\McpTool;

final class MyCustomTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    #[McpTool(name: 'typo3-my-thing', description: '…')]
    public function run(int $pageId): array
    {
        return $this->typo3->json('myext:something', [$pageId]); // shells out, parses JSON
    }
}
```
