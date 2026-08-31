# Tool reference

Every tool this extension registers, what it answers, and a short use case for each. The routing table an assistant reads at session start lives in `INSTRUCTIONS.md`, which `mate discover` materializes into `mate/AGENT_INSTRUCTIONS.md`; this page is the version for humans.

All of them are read-only except `typo3-profiler-start`, `-stop` and `typo3-render-page`. See [Security](security.md).

| Area | Tool |
| --- | --- |
| Start here | [`typo3-info`](#typo3-info) |
| Profiling | [`typo3-profiler-latest` / `-list` / `-search` / `-get`](#typo3-profiler-latest---list---search---get) |
| Profiling control | [`typo3-profiler-start` / `-stop` / `-status`](#typo3-profiler-start---stop---status) |
| Page | [`typo3-page`](#typo3-page) |
| Records | [`typo3-records`](#typo3-records) |
| Logs | [`typo3-logs-search` / `-tail` / `-by-level`](#typo3-logs-search---tail---by-level) |
| TCA | [`typo3-tca`](#typo3-tca) |
| Database | [`typo3-db-schema`](#typo3-db-schema) |
| FlexForm | [`typo3-flexform`](#typo3-flexform) |
| TypoScript | [`typo3-typoscript`](#typo3-typoscript) |
| TSconfig | [`typo3-tsconfig`](#typo3-tsconfig) |
| Fluid | [`typo3-fluid-resolve`](#typo3-fluid-resolve) |
| Fluid | [`typo3-fluid-namespaces`](#typo3-fluid-namespaces) |
| Icons | [`typo3-icons`](#typo3-icons) |
| Backend modules | [`typo3-backend-modules`](#typo3-backend-modules) |
| Middlewares | [`typo3-middlewares`](#typo3-middlewares) |
| Events | [`typo3-events`](#typo3-events) |
| Sites | [`typo3-site`](#typo3-site) |
| Console | [`typo3-commands`](#typo3-commands) |
| Configuration | [`typo3-config`](#typo3-config) |
| Upgrade | [`typo3-upgrade-wizards`](#typo3-upgrade-wizards) |
| Upgrade | [`typo3-extension-scanner`](#typo3-extension-scanner) |
| Upgrade | [`typo3-changelog-search`](#typo3-changelog-search) |
| Deprecations | [`typo3-deprecations`](#typo3-deprecations) |
| Rendering | [`typo3-render-page`](#typo3-render-page) |

## `typo3-info`

Session entry point. Exact TYPO3 version and major (v13 versus v14 governs almost every other recommendation), PHP version, application context, database platform, active extensions split into own and third-party, relevant package versions, profiler CLI availability, and how many `tt_content` types are registered. Do not derive any of this from `composer.json`: a constraint range does not resolve to a single version.

<details>
<summary>Establish the version before anything else</summary>

```bash
vendor/bin/mate tools:call typo3-info
vendor/bin/mate tools:call typo3-info --contentTypes=true
```

The second form adds the `tt_content` type catalogue, which the default response only counts.

</details>

## `typo3-profiler-latest` / `-list` / `-search` / `-get`

Inspect recorded per-request profiles as compact summaries (timing, N+1, cache, `page.id`, `activation_mode`), each linking a `typo3-profiler://profile/{token}` resource for the full SQL/section detail.

<details>
<summary>Diagnose one slow page</summary>

```bash
vendor/bin/mate tools:call typo3-profiler-latest
vendor/bin/mate tools:call typo3-profiler-search --url=/checkout --status=500
vendor/bin/mate tools:call typo3-profiler-get --token=<token>
```

Start with `-latest` for a single complaint. `typo3-profiler-search` when you already know a URL fragment or status code, `typo3-profiler-list --limit=20` to browse. Each summary carries a `resource_uri` for the full SQL detail.

</details>

## `typo3-profiler-start` / `-stop` / `-status`

Enable request profiling for a bounded window (`duration` e.g. `15m`, capped at 60 minutes), disable it again, and report the remaining time — so an agent can turn profiling on, exercise the site and read the resulting profiles in one session. Writes go through the profiler's own `profiler:activate`/`:deactivate` commands and therefore require those to be registered as console commands; `-status` reads the state file directly and therefore reflects only this window, not the Development context or the per-request header trigger (a profile's `activation_mode` records which mode actually applied).

<details>
<summary>Record a profile that does not exist yet</summary>

```bash
vendor/bin/mate tools:call typo3-profiler-start --duration=15m
# exercise the page in a browser
vendor/bin/mate tools:call typo3-profiler-latest
vendor/bin/mate tools:call typo3-profiler-stop
```

`--duration` is capped at 60 minutes. `typo3-profiler-status` only reflects this window, not the Development context or a per-request header; read `activation_mode` on a recorded profile to see which mode actually applied.

</details>

## `typo3-page`

Show a page's composition: content elements, cache signals and `USER_INT` plugins.

<details>
<summary>Expand a slow page into what rendered on it</summary>

```bash
vendor/bin/mate tools:call typo3-page --pageId=42
vendor/bin/mate tools:call typo3-page --url=/products/widget
```

`user_int_plugins` is the field to read first on a slow page: those render uncached on every request.

</details>

## `typo3-records`

Read-only record query for any table (structured, parameterised — equality filters via `uid`/`pid`/`where`, never raw SQL). Returns compact rows (uid, pid, label/type, enable columns, timestamps; long text truncated) each with a `_flags` list (hidden/deleted/timed/fe_group). No restrictions by default so hidden/deleted rows are visible — the answer to "why is this record not showing?". Pass `fields` for specific columns, `mode=full` for all columns, `respectEnableFields=true` for the frontend view. Sensitive columns (passwords and `password`-type TCA fields) are always redacted, and any embedded emails, IPv4 addresses or secrets in text values are redacted too.

<details>
<summary>Answer "why is this record not showing?"</summary>

```bash
vendor/bin/mate tools:call typo3-records --table=tt_content --pid=42
vendor/bin/mate tools:call typo3-records --table=pages --uid=42 --mode=full
vendor/bin/mate tools:call typo3-records --table=tt_content --where=CType=text,colPos=0 --limit=10
```

No restrictions apply by default, so hidden and deleted rows are visible with a `_flags` list explaining why. Pass `--respectEnableFields=true` for the frontend view instead.

</details>

## `typo3-logs-search` / `-tail` / `-by-level`

Search, tail or filter the TYPO3 logs. Returns a compact summary (distinct messages with occurrence counts and `lastSeen`, no stack traces) by default; pass `mode=full` for individual entries with truncated traces, and `since` (e.g. `1h`, `2d`) to scope to recent entries. Emails, IPv4 addresses and secrets embedded in messages/traces are redacted before output.

<details>
<summary>Find an exception and tie it to its request</summary>

```bash
vendor/bin/mate tools:call typo3-logs-by-level --level=error --since=1h
vendor/bin/mate tools:call typo3-logs-search --query="Call to a member function" --mode=full
vendor/bin/mate tools:call typo3-logs-tail --limit=20
```

A log entry's `request_id` is the profiler `token`, so it feeds straight into `typo3-profiler-get --token=…`.

</details>

## `typo3-tca`

Dump the resolved (merged, trimmed) TCA of a table, or narrow it to one `recordType` or a set of `fields`.

<details>
<summary>Ask narrowly, not for the whole table</summary>

```bash
vendor/bin/mate tools:call typo3-tca --list
vendor/bin/mate tools:call typo3-tca --table=tt_content --fields=header,bodytext
vendor/bin/mate tools:call typo3-tca --table=tt_content --recordType=textmedia
```

For `tt_content` the difference between a scoped and an unscoped call is a few hundred bytes versus roughly 15 kB that every later turn re-reads.

</details>

## `typo3-db-schema`

The physical database schema, and the counterpart to `typo3-tca`. Without a table: every table name with a row-count estimate. With a table: its real columns (name, type, length, nullable, default), indexes and foreign keys.

<details>
<summary>Answer "why is my field not persisted?"</summary>

```bash
vendor/bin/mate tools:call typo3-db-schema --table=tt_content
vendor/bin/mate tools:call typo3-db-schema --pattern=tx_news
```

A TCA field with no matching column is the usual cause. `typo3-tca` is the semantic model, `typo3-records` the data, this is the schema underneath both.

</details>

## `typo3-flexform`

Reconcile a record's stored FlexForm against the data structure currently valid for it, resolved through `FlexFormTools` from the record's own pointer field. Reports `orphaned` values (stored but no longer declared, so silently ignored at runtime — what a renamed field looks like) and `missing` fields (declared but not stored, so the default applies). A record without a FlexForm answers `hasFlexForm: false` rather than an empty structure.

<details>
<summary>Explain a plugin setting that stopped working</summary>

```bash
vendor/bin/mate tools:call typo3-flexform --table=tt_content --uid=7
```

`orphaned` values are stored but no longer declared, so they are silently ignored at runtime. That is what a renamed FlexForm field looks like from the outside.

</details>

## `typo3-typoscript`

Dump the resolved frontend TypoScript of a page.

<details>
<summary>Read the resolved setup, scoped</summary>

```bash
vendor/bin/mate tools:call typo3-typoscript --pageId=1 --path=lib.contentElement
vendor/bin/mate tools:call typo3-typoscript --pageId=1 --type=constants
```

Without `--path` you get an overview. Pass `--full=true` only when you genuinely need the whole tree.

</details>

## `typo3-tsconfig`

Dump the resolved Page TSconfig (rootline-merged: `mod.*`, `TCEFORM`, `TCEMAIN`, RTE) or User TSconfig — the backend configuration layer that no single file reveals. Scope large output with a dotted path, e.g. `mod.web_layout`.

<details>
<summary>Find which TCEFORM rule wins</summary>

```bash
vendor/bin/mate tools:call typo3-tsconfig --pageId=42 --path=TCEFORM.tt_content
vendor/bin/mate tools:call typo3-tsconfig --type=user --user=1
```

Page TSconfig is rootline-merged, so no single file reveals the effective value.

</details>

## `typo3-fluid-resolve`

Resolve the `templateRootPaths`/`partialRootPaths`/`layoutRootPaths` override chain for a plugin/view (e.g. `plugin.tx_news_pi1`) and report which physical template/partial/layout file wins — ordered candidate directories with `exists` flags plus the resolved file.

<details>
<summary>Find out which template file actually wins</summary>

```bash
vendor/bin/mate tools:call typo3-fluid-resolve --pageId=1 --plugin=plugin.tx_news_pi1 --template=List
```

Returns the ordered candidate directories with `exists` flags plus the resolved file, which is the answer to "my override is being ignored".

</details>

## `typo3-fluid-namespaces`

Which ViewHelper prefixes a template may use without declaring them, mapped to the PHP namespaces they resolve to in order. Takes no arguments. Read from the `ViewHelperResolver`, so on v14 it already reflects the merge of every package's `Configuration/Fluid/Namespaces.php` with the deprecated `TYPO3_CONF_VARS` registration and any `ModifyNamespacesEvent` listener.

<details>
<summary>Check a ViewHelper prefix before using it</summary>

```bash
vendor/bin/mate tools:call typo3-fluid-namespaces
```

Read from the `ViewHelperResolver`, so on v14 it already reflects every package's `Configuration/Fluid/Namespaces.php` merged with the deprecated registration and any `ModifyNamespacesEvent` listener.

</details>

## `typo3-icons`

Whether icon identifiers are registered and which extension provides them. `registered: false` is the answer, not an empty result — an unregistered identifier renders no icon at all. A miss carries the closest registered identifiers as suggestions, so a half-remembered name is answered rather than denied; without arguments you get the identifier count grouped by leading segment.

<details>
<summary>Verify an icon identifier exists</summary>

```bash
vendor/bin/mate tools:call typo3-icons --identifiers=actions-add,actions-edit
vendor/bin/mate tools:call typo3-icons
```

`registered: false` is the answer, not an empty result: an unregistered identifier renders no icon at all. A miss carries the closest registered identifiers as suggestions.

</details>

## `typo3-backend-modules`

Registered backend modules with their parent, route path, access level and *resolved* navigation component — a submodule declaring `inheritNavigationComponent` reports its parent's, which its own `Configuration/Backend/Modules.php` does not show. Takes no arguments, applies no user context.

<details>
<summary>See a submodule's inherited navigation component</summary>

```bash
vendor/bin/mate tools:call typo3-backend-modules
```

A submodule declaring `inheritNavigationComponent` reports its parent's resolved component, which its own `Configuration/Backend/Modules.php` does not show.

</details>

## `typo3-middlewares`

List the resolved PSR-15 middleware order.

<details>
<summary>Read the real PSR-15 order</summary>

```bash
vendor/bin/mate tools:call typo3-middlewares --stack=frontend
vendor/bin/mate tools:call typo3-middlewares --stack=backend
```

The resolved order after every `Configuration/RequestMiddlewares.php` has been merged and sorted, which no single file shows.

</details>

## `typo3-events`

List the resolved PSR-14 event listener registry.

<details>
<summary>See who already listens to an event</summary>

```bash
vendor/bin/mate tools:call typo3-events --event=ModifyRecordListRecordActions
```

Reads the PSR-14 registry only. It says nothing about legacy `SC_OPTIONS` hook arrays.

</details>

## `typo3-site`

Configured sites: identifier, base URL, root page id, languages (id, locale, base path, title) and error handling. Also resolves a page id to its absolute frontend URL, which is what `typo3-render-page` needs.

<details>
<summary>Resolve a page id to a URL without rendering it</summary>

```bash
vendor/bin/mate tools:call typo3-site
vendor/bin/mate tools:call typo3-site --pageId=42 --language=1
```

Use this instead of `typo3-render-page` when you only want the URL and not the request and its side effects.

</details>

## `typo3-commands`

All registered console commands with name, description and synopsis, including those from third-party extensions. Read this instead of guessing CLI commands from another framework or TYPO3 version.

<details>
<summary>Check a command exists before suggesting it</summary>

```bash
vendor/bin/mate tools:call typo3-commands --pattern=cache
vendor/bin/mate tools:call typo3-commands --ownOnly=true
```

`--ownOnly=true` hides core and vendor commands, leaving what this project itself registers.

</details>

## `typo3-config`

`TYPO3_CONF_VARS`, feature toggles, or one extension's configuration. Because `settings.php`, `additional.php` and environment variables only produce the effective value at runtime, this is the only reliable way to read it. Secrets are masked recursively by key and remaining strings are scanned for embedded credentials; masking cannot be disabled.

<details>
<summary>Read an effective setting rather than guessing</summary>

```bash
vendor/bin/mate tools:call typo3-config --path=SYS.features
vendor/bin/mate tools:call typo3-config --section=extensions --path=news
```

Omit `--path` for a compact overview, then drill in.

</details>

## `typo3-upgrade-wizards`

List pending and completed upgrade wizards — outstanding DB/config migrations.

<details>
<summary>See what an upgrade still owes you</summary>

```bash
vendor/bin/mate tools:call typo3-upgrade-wizards
```

Read-only. Never run wizards autonomously on someone's installation.

</details>

## `typo3-extension-scanner`

Statically scan an extension — or all non-core extensions — against the core breaking/deprecation matchers. Returns a compact summary by default (matches grouped by message with strong/weak counts and the affected files, plus a per-origin rollup when scanning all); pass `mode=full` for individual matches with line content, and `ownCode=true` to skip third-party (vendor) packages.

<details>
<summary>Start an LTS jump here</summary>

```bash
vendor/bin/mate tools:call typo3-extension-scanner --extension=my_ext
vendor/bin/mate tools:call typo3-extension-scanner --ownCode=true --mode=full
```

Omit `--extension` to sweep every non-core extension. Feed a hit into `typo3-changelog-search` for the migration path.

</details>

## `typo3-changelog-search`

Search the installed `typo3/cms-core` changelog (the Breaking, Deprecation, Feature and Important RST files) for migration guidance. Offline, from the installed core, with no training-data guessing.

<details>
<summary>Turn a scanner hit into a migration path</summary>

```bash
vendor/bin/mate tools:call typo3-changelog-search --query=sys_file_reference
vendor/bin/mate tools:call typo3-changelog-search --query=CType --type=breaking --version=13
```

The scanner finds *that* an API breaks; this finds *how* to migrate it.

</details>

## `typo3-deprecations`

Report runtime deprecation notices, deduplicated and counted. Each one carries `origins` — the likely caller in own code. With deprecation logging enabled, a dev-only log processor records the caller's backtrace at log time for a high-confidence file:line; otherwise it falls back to a class-aware static reverse search across own PHP/Fluid files.

<details>
<summary>See what actually fired, not what might</summary>

```bash
vendor/bin/mate tools:call typo3-render-page --pageId=1
vendor/bin/mate tools:call typo3-deprecations
```

Render first, so runtime notices fire. Check `loggingEnabled`: when it is `false` the channel is off and an empty list means "not measured", not "none".

</details>

## `typo3-render-page`

Render a frontend page via an internal HTTP request (no external curl/Playwright) so runtime notices fire, and report the HTTP status plus the log entries written during that request. Requires a running webserver (e.g. DDEV). An explicit `--url` is only allowed for the installation's configured site hosts (SSRF guard) and the request is capped at 60s.

<details>
<summary>Make runtime notices fire</summary>

```bash
vendor/bin/mate tools:call typo3-render-page --pageId=1
vendor/bin/mate tools:call typo3-render-page --url=https://example.ddev.site/ --language=1
```

Requires a running webserver. An explicit `--url` is rejected unless its host matches a configured site base, and the request is capped at 60 seconds.

</details>

## Clusters that may have nothing to report yet

All `typo3-*` tools are always callable — `mate`'s tool list is fixed at discovery time from the `#[MateTool]` attributes in this extension, nothing here changes it at runtime. Two clusters can still come back empty though: the profile-reading tools (`typo3-profiler-latest` / `-list` / `-search` / `-get`) until a profile exists or profiling is active, and the log search tools (`typo3-logs-search`, `typo3-logs-by-level`) until the log has entries — each answers with an honest `{"unsupported": true, "reason": "..."}` rather than an empty success.

`typo3-info` reports the current state under `toolClusters` (evaluated fresh on every call, from the filesystem) with a reason for each, so an assistant can check first instead of finding out via a wasted call.
