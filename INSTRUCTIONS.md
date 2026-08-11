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
- `typo3-changelog-search` — search the installed core's shipped changelog
  (Breaking/Deprecation/Feature/Important RST files) offline for migration
  guidance. Scoped to the installed TYPO3 major by default — the core ships
  every historical version, so pass `version` explicitly to widen the search.
  Each result has `type`, `issue`, `version`, `title`, a bounded `excerpt` and
  the relative `path` to read the full file.

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

*Which of my own code breaks* is answered from the installation's real state, not
the changelog — the changelog does not know your code. *How do I migrate a specific
hit*, once found, is answered from the changelog itself (`typo3-changelog-search`):

1. `typo3-extension-scanner extension=<key>` — static analysis: which lines in your
   own code break / are deprecated in the installed target version (`message`,
   `line`, `strong`/`weak` `indicator`). Biggest lever, runs headless. Omit
   `extension` to sweep all non-core extensions in one call.
2. `typo3-changelog-search query="<the affected class/method/hook>"` — the
   migration path for a specific hit from (1), read straight from the installed
   core's shipped changelog.
3. `typo3-upgrade-wizards` — which DB/config migrations are still `AVAILABLE` vs
   `DONE`. Read-only; the assistant must not run wizards autonomously.
4. `typo3-deprecations` — what actually logged a deprecation at runtime,
   deduplicated by message. Complements (1). If `loggingEnabled` is `false`, the
   `deprecations` log channel is off (the default) — an empty list means "not
   measured", **not** "no deprecations". Enable
   `[LOG][TYPO3][CMS][deprecations][writerConfiguration]` to collect data. Feed a
   hit back into (2) to close the loop.

---

Everything above answers *what does this installation actually do* — a tool
wayfinder. Everything below answers *how do I write TYPO3 code here*,
independent of any tool: apply these whenever writing or editing PHP/Fluid in
this project, not just during an upgrade.

## TYPO3 coding conventions

**Database access — use DBAL, never raw SQL strings.**
Get a `QueryBuilder` from `TYPO3\CMS\Core\Database\ConnectionPool`
(`getQueryBuilderForTable()`) instead of writing SQL by hand or reaching for
PDO/mysqli directly. Always bind values via `$queryBuilder->createNamedParameter()`
— never interpolate a variable into the query string (SQL injection). Both
v13.4 and v14 ship Doctrine DBAL 4 (introduced in TYPO3 13.0, not a v13/v14
split), which removed `execute()` and changed a few signatures — write new
code against the current API directly rather than the DBAL 3 style:

| Instead of (DBAL 3 / TYPO3 ≤12)         | Use (DBAL 4 / TYPO3 13.4+)                          |
| ---------------------------------------- | ---------------------------------------------------- |
| `$queryBuilder->execute()` (select)      | `->executeQuery()`                                   |
| `$queryBuilder->execute()` (insert/update/delete) | `->executeStatement()`                      |
| `$queryBuilder->setMaxResults(0)` for "no limit" | `->setMaxResults(null)` (`0` now returns nothing) |
| `$queryBuilder->add('where', $x)` / `resetQueryPart(...)` | the dedicated method: `->where()`, `->resetWhere()`, etc. |

**Enable-field restrictions are automatic for reads only — do not assume they cover writes.**
A `QueryBuilder` from `getQueryBuilderForTable()` applies the table's
`enablecolumns` restrictions (deleted/hidden/start-and-endtime) by default,
but only to `SELECT`/`COUNT` (`executeQuery()`); `UPDATE`/`DELETE`
(`executeStatement()`) build no such `WHERE` clause and silently affect
every matching row regardless of those flags — write it explicitly if a
write must respect them. The frontend user-group restriction is not part
of this default either: it only applies once the restriction container is
explicitly swapped to `TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer`
(which also adds the workspace restriction), not merely by running in FE
context. Raw SQL, or a `QueryBuilder` with `->getRestrictions()->removeAll()`
called without a reason, silently shows hidden/deleted/time-restricted rows
— a frequent source of "why does this appear in production" bugs. Only call
`removeAll()` deliberately (e.g. a backend admin listing), and say why in a
comment. `typo3-records` shows exactly which flags apply to a given row.

**Prefer PSR-14 events over legacy hooks in new code.**
TYPO3 has migrated most extension points to PSR-14 events; write new
extension points as events and use them instead of registering into an old
hook array. `typo3-events` shows the resolved PSR-14 listener registry for
this installation — use it to see which listeners are already attached to
an event, not to check whether a legacy hook array still exists (it only
reads the PSR-14 registry, not `SC_OPTIONS` or other hook arrays).

**Fluid escapes output by default — do not disable that carelessly.**
`{variable}` in a Fluid template is HTML-escaped automatically. Never wrap
user-controlled or otherwise untrusted data in `f:format.raw()` (or set
`escapeOutput = false` on a custom ViewHelper) without a specific,
deliberate reason — that is the direct path to a stored-XSS bug.

*v14 only — Fluid 5 (`Breaking-108148-*`):* ViewHelper argument validation
is stricter (a previously-tolerated but wrong argument type now fails
instead of silently misbehaving); a `null` passed to a tag-based ViewHelper
(`f:form.*`, `f:image`, `f:media`, `f:link.*`, `f:asset.*`, …) now omits the
HTML attribute entirely instead of rendering it empty (`attr=""`); and a
custom ViewHelper's `initializeArguments()` must declare `void` and
`render()` must declare a real return type (`mixed` is acceptable, `void`
for `render()` is not).

**Dependency injection — constructor injection over `GeneralUtility::makeInstance()`, with one exception.**
For your own service classes, declare dependencies as constructor
parameters and let TYPO3's DI container (autowiring, `Services.yaml`)
supply them; do not reach for `GeneralUtility::makeInstance()` in new
service code to get a shared, cached instance. Two on-demand cases remain
correct: objects TYPO3 itself creates outside DI (TCA-referenced classes,
ViewHelpers, hook targets), and a *stateful* service that must get a
genuinely fresh instance per use — constructor-injecting it would silently
turn a per-use object into a de-facto singleton for the lifetime of the
injecting service, a shared-state bug. Only a class implementing
`TYPO3\CMS\Core\SingletonInterface` is safe to hold as a long-lived
injected property; `makeInstance()` on anything else always returns a new
instance.

*v14 only:* the `tt_content.list_type` column is gone — third-party plugins
that used to register under `list_type` now register a dedicated `CType`
instead. Do not write code that reads or writes `list_type` when targeting
v14; check `typo3-tca` before assuming the column exists.

**Version awareness.** `typo3-info` reports the exact installed TYPO3
version and major up front — read it before writing version-conditional
code instead of guessing from `composer.json`'s constraint range (this
package's own `^13.4 || ^14.3` does not resolve to a single version by
inspection alone).
