# Use cases

Concrete, tool-by-tool flows an assistant can follow against the **resolved runtime state** of an installation. Each flow ends at the `request_id` anchor, which ties a profile, a page and its log lines back to one and the same request.

## Slow page

_"This page is slow — find the performance problem."_

1. **`typo3-profiler-latest`** — read the most recent request profile. It surfaces the SQL the request ran, normalised N+1 patterns (the same query shape repeated dozens of times), the cache state (`hit` / `cacheable`) and the overall timing.
2. **`typo3-page`** — for the page in question, inspect its composition: content elements, `colPos`, and the cache signals that explain *why* it was (not) cached — `no_cache`, a low `cache_timeout`, or a `USER_INT` plugin forcing an uncached render.
3. **Correlate** via `request_id`: the profile's `token` is the same id that appears in the matching log lines, so the slow query, the uncached block and the page that produced them line up.

Typical outcome: "Element X on this page runs a `USER_INT` plugin that fires the same query per row → N+1; either cache it or batch the lookup."

## Error page

A page throws, or behaves wrongly, and you need the actual exception — not a guess.

1. **`typo3-logs-search`** / **`typo3-logs-by-level`** — search the TYPO3 logs (filter by level, component or a free-text query). Exceptions are extracted with their message and stack trace, so the assistant sees the real failure instead of inferring it from source.
2. **`typo3-page`** — pull the composition of the page that produced the error for context: which content elements and plugins are involved, and whether caching masked or amplified the problem.
3. **Correlate** via `request_id`: the log line carries `request="…"`, which matches the profile and the page of that exact request.

Typical outcome: "The 500 on this page comes from plugin Y dereferencing a null record; here is the trace and the element that renders it."

## Major upgrade (any LTS jump, e.g. v13 → v14)

The three questions every major upgrade repeats, answered from the installation's real state rather than the core changelog:

1. **`typo3-extension-scanner`** — *which of my own code breaks?* A static scan of an extension against the core's breaking/deprecation matchers, reporting file, line, the offending line content and a `strong`/`weak` indicator.
2. **`typo3-upgrade-wizards`** — *which DB/config migrations are still outstanding?* Lists pending and completed upgrade wizards with identifier, title, description and status.
3. **`typo3-deprecations`** — *what did the running installation actually flag as deprecated?* Runtime deprecation notices, deduplicated and counted, complementing the static scan. Note: the deprecation log channel is disabled by default — the tool reports when logging is off so an empty result is not mistaken for "no deprecations".

Typical outcome: "Code: 4 strong hits in `Classes/`. Migrations: 2 wizards still pending. Runtime: 12 distinct deprecations, top one hit 37×."

## Correlation anchor `request_id`

`request_id` (= the profile's `token` = `Core\RequestId`, also written into every log line as `request="…"`) is the join key across all three flows. Given any one of a profile, a page render or a log entry, the assistant can pull the other two for the same request — which is what turns separate facts into a single diagnosis.
