# TYPO3 AI Mate — tool instructions

These tools expose the **resolved runtime state** of a TYPO3 installation (dev
context only). Prefer them over reading source files: they report what TYPO3
actually computed, not what the code might do.

## Diagnose instead of guessing

**"This page is slow":**
1. `typo3-profiler-latest` — a compact summary: timing, `query_count`, `duplicate_queries`
   count (N+1), `cache_hit`, the `page.id` and a `resource_uri`.
2. For the raw SQL / per-section detail, read the profile as a resource:
   `typo3-profiler://profile/<token>` (full) or `typo3-profiler://profile/<token>/queries`
   (a single section).
3. `typo3-page` with that `page.id` — see the content elements and which plugins
   are `USER_INT` (uncached). Attribute the N+1 / cache miss to a concrete element.
4. Optionally `typo3-typoscript pageId=<id> path=...` to inspect the plugin's setup.

**"This page errors":**
1. `typo3-logs-search query="..."` (or `typo3-logs-by-level level=error`) — find
   the exception, class and stack trace.
2. Use the entry's `request_id` to fetch the matching profile summary
   (`typo3-profiler-get token=<request_id>`, then read `typo3-profiler://profile/<request_id>`
   for the full data) and page (`typo3-page`).

## Correlation anchor: `request_id`

`request_id` (= profile `token` = TYPO3 `Core\RequestId`, logged as `request="…"`)
links every request-scoped tool:

```
request_id ──┬── typo3-profiler-*  (SQL, N+1, timing, page.id)
             ├── typo3-page        (content elements / USER_INT plugins)
             └── typo3-logs-*      (exception + stack trace)
```

## Tools

- `typo3-profiler-latest` / `-list` / `-search` / `-get` — request profiles as compact
  summaries, each with a `resource_uri`; read the full profile or a single section via the
  `typo3-profiler://profile/{token}[/{section}]` resources. (Requires the
  `typo3-request-profiler` extension and a triggered FE request in the Development context.)
- `typo3-page` — page composition + cache signals (expand a profile `page.id`).
- `typo3-logs-search` / `-tail` / `-by-level` — TYPO3 logs.
- `typo3-tca` — resolved (trimmed) TCA of a table, or all table names.
- `typo3-typoscript` — resolved frontend TypoScript of a page (scope with `path`).
- `typo3-middlewares` — resolved PSR-15 middleware order of a stack.
- `typo3-events` — resolved PSR-14 event listener registry (event => listeners).
- `typo3-upgrade-wizards` — all upgrade wizards (pending/done) with status; which
  DB/config migrations are still outstanding. Read-only.
- `typo3-extension-scanner` — static scan of an extension's PHP against the core
  breaking/deprecation matchers (`extension=<key>`); where *your* code breaks. Omit
  `extension` to scan all non-core extensions (own + third-party) at once.
- `typo3-deprecations` — runtime deprecation notices, deduplicated and grouped by
  message with counts (`loggingEnabled` flag — see below).

## Output size

Some tools cap large output and signal it with `_truncated: true` plus a total count, so a
big result never floods the context. Treat a truncated result as a sample, not the whole
picture, and re-query with a narrower filter:

- `typo3-extension-scanner` — at most 200 `matches` per extension (`statistics.matchCount`
  is the real total). If truncated, scan a single `extension=<key>` to focus.
- `typo3-events` — at most 100 events (`eventCount` is the real total). Narrow with the
  `event` class-substring filter.

## Planning a major upgrade (v13 → v14)

Combine the three upgrade tools — the same building blocks as the backend upgrade
module — to reason from the installation's real state instead of the changelog:

1. `typo3-extension-scanner extension=<key>` — static analysis: which lines in your
   own code break / are deprecated in the installed target version (`message`,
   `line`, `strong`/`weak` `indicator`). Biggest lever, runs headless. Omit
   `extension` to sweep all non-core extensions in one call.
2. `typo3-upgrade-wizards` — which DB/config migrations are still `AVAILABLE` vs
   `DONE`. Read-only; the assistant must not run wizards autonomously.
3. `typo3-deprecations` — what actually logged a deprecation at runtime,
   deduplicated by message. Complements (1). If `loggingEnabled` is `false`, the
   `deprecations` log channel is off (the default) — an empty list means "not
   measured", **not** "no deprecations". Enable
   `[LOG][TYPO3][CMS][deprecations][writerConfiguration]` to collect data.
