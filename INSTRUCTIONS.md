# TYPO3 AI Mate — tool instructions

These tools expose the **resolved runtime state** of a TYPO3 installation (dev
context only). Prefer them over reading source files: they report what TYPO3
actually computed, not what the code might do.

## Start here

Call `typo3-info` first, before any other tool in this package. It reports the
exact TYPO3 version and major (v13 vs v14 governs almost every subsequent
recommendation), PHP version, application context, database platform/version,
active extensions (own vs. third-party), relevant package versions, profiler
CLI availability, and a compact `tt_content` type inventory. Do not derive any
of this from `composer.json` — its constraint ranges (e.g. `^13.4 || ^14.3`,
this package's own) do not resolve to a single version.

## Use a tool instead of reading files

| Instead of reading…                  | …use                  |
| ------------------------------------ | --------------------- |
| `Configuration/TCA/*.php`            | `typo3-tca`           |
| `*.typoscript` / guessing the setup  | `typo3-typoscript`    |
| `RequestMiddlewares.php`             | `typo3-middlewares`   |
| event listeners in `Services.yaml`   | `typo3-events`        |
| guessing available CLI commands      | `typo3-commands`      |
| `tail -f var/log/*.log`              | `typo3-logs-tail`     |
| raw `SELECT` / `ddev mysql`          | `typo3-records`       |
| `ext_tables.sql` / a DB client       | `typo3-db-schema`     |
| `settings.php` / `additional.php`    | `typo3-config`        |
| `config/sites/*/config.yaml`         | `typo3-site`          |

## Diagnose instead of guessing

**"This page is slow":**
0. If no profile exists yet, or `typo3-profiler-latest` returns a stale one:
   `typo3-profiler-start duration=15m`, exercise the page, then continue below.
   `typo3-profiler-stop` when done — profiling every request is expensive.
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

- `typo3-info` — **call this first.** Version/context/database facts, extensions,
  package versions, profiler state, content-type inventory. See "Start here" above.
- `typo3-profiler-latest` / `-list` / `-search` / `-get` — request profiles as compact
  summaries, each with a `resource_uri`; read the full profile or a single section via the
  `typo3-profiler://profile/{token}[/{section}]` resources. (Requires the
  `typo3-request-profiler` extension and a triggered FE request in the Development context.)
- `typo3-profiler-start` / `-stop` / `-status` — switch profiling on for a bounded window
  (`duration=15m`, max `60m`), off again, and check the remaining time. `-status` covers only
  this window; profiling can also be on via the Development context or a per-request header,
  so read `activation_mode` on a recorded profile to see which mode actually applied.
  `-start`/`-stop` need the profiler's `profiler:activate`/`:deactivate` console commands; if
  they are not registered, the tools say so and profiling stays controlled by the context.
- `typo3-page` — page composition + cache signals (expand a profile `page.id`).
- `typo3-records` — read-only record query for any table (`table=<name>`, equality
  filters via `uid`/`pid`/`where=field=value,…`). Compact rows with a `_flags` list
  (hidden/deleted/timed/fe_group); no restrictions by default. Use instead of raw
  SQL to answer "why is this record not showing?". `mode=full` for all columns,
  `respectEnableFields=true` for the frontend view.
- `typo3-logs-search` / `-tail` / `-by-level` — TYPO3 logs.
- `typo3-tca` — resolved TCA of a table, or all table names. Built on the core
  Schema API: `capabilities` (softDelete/workspace/language/sorting field),
  `recordTypes` (type value => visible field names) and `relations` (field =>
  resolved target table + relationship type — a file field resolves to
  `sys_file_reference` instead of leaving `type: file` for you to interpret),
  plus the trimmed `columns` as before.
- `typo3-db-schema` — the physical schema, counterpart to `typo3-tca`: without a
  `table`, every table name with a row-count estimate (optional `pattern` substring
  filter); with a `table`, its real columns (name, type, length, nullable, default),
  indexes (name, columns, unique) and foreign keys. Use it to answer "why is my field
  not persisted" (TCA field vs. real column) or to spot a missing index.
- `typo3-typoscript` — resolved frontend TypoScript of a page. Returns a top-level
  overview by default; scope with `path` or pass `full=true` for the whole tree.
- `typo3-site` — configured sites (identifier, base, root page id, languages,
  error handling), or `pageId=<id>` to resolve the frontend URL (via the site
  router, same resolution `typo3-render-page` uses — a lookup, no rendering)
  plus the matching backend URL. `pageId=0` falls back to the root page of the
  first configured site.
- `typo3-middlewares` — resolved PSR-15 middleware order of a stack.
- `typo3-events` — resolved PSR-14 event listener registry (event => listeners).
- `typo3-commands` — every registered console command (name, description, synopsis),
  third-party extensions included. Read this instead of guessing CLI commands carried
  over from other frameworks or older TYPO3 versions. Optional `pattern` substring
  filter on the command name; `ownOnly=true` hides core and third-party (vendor)
  commands.
- `typo3-config` — `TYPO3_CONF_VARS`, feature toggles (`section=features`) or one
  extension's configuration (`section=extension`, `path=<extension key>`). Omit
  `path` for a compact overview (top-level keys plus feature toggles by default).
  Secrets are masked recursively by key, plus a second pass over string values
  for embedded credentials (DSNs); masking cannot be disabled.
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
- `typo3-db-schema` (table list) — at most 300 tables (`tableCount` is the real total).
  Narrow with the `pattern` substring filter.

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
