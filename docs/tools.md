# Tool reference

Every tool this extension registers, what it answers, and a short use case for each. The routing table an assistant reads at session start lives in `INSTRUCTIONS.md`, which `mate discover` materializes into `mate/AGENT_INSTRUCTIONS.md`; this page is the version for humans.

All of them are read-only except `typo3-profiler-start`, `-stop` and `typo3-render-page`. See [Security](security.md).

The examples below omit `--format=toon` for readability. An assistant should pass it on every call: see [tool surface](tool-surface.md) for what the default format costs.

| Area | Tool |
| --- | --- |
| Start here | [`typo3-info`](#typo3-info) |
| Profiling | [`typo3-profiler-latest` / `-list` / `-search` / `-get`](#typo3-profiler-latest---list---search---get) |
| Profiling analysis | [`typo3-profiler-compare` / `-triage`](#typo3-profiler-compare---triage) |
| Profiling control | [`typo3-profiler-start` / `-stop` / `-status`](#typo3-profiler-start---stop---status) |
| Page | [`typo3-page`](#typo3-page) |
| Records | [`typo3-records`](#typo3-records) |
| Logs | [`typo3-logs-search` / `-tail` / `-by-level`](#typo3-logs-search---tail---by-level) |
| System log | [`typo3-syslog`](#typo3-syslog) |
| TCA | [`typo3-tca`](#typo3-tca) |
| Database | [`typo3-db-schema`](#typo3-db-schema) |
| FlexForm | [`typo3-flexform`](#typo3-flexform) |
| TypoScript | [`typo3-typoscript`](#typo3-typoscript) |
| TSconfig | [`typo3-tsconfig`](#typo3-tsconfig) |
| Backend permissions | [`typo3-be-permissions`](#typo3-be-permissions) |
| Fluid | [`typo3-fluid-resolve`](#typo3-fluid-resolve) |
| Fluid | [`typo3-fluid-namespaces`](#typo3-fluid-namespaces) |
| Fluid | [`typo3-viewhelpers`](#typo3-viewhelpers) |
| Icons | [`typo3-icons`](#typo3-icons) |
| Labels | [`typo3-labels`](#typo3-labels) |
| Backend modules | [`typo3-backend-modules`](#typo3-backend-modules) |
| Middlewares | [`typo3-middlewares`](#typo3-middlewares) |
| Events | [`typo3-events`](#typo3-events) |
| Sites | [`typo3-site`](#typo3-site) |
| Routing | [`typo3-route`](#typo3-route) |
| Console | [`typo3-commands`](#typo3-commands) |
| Scheduler | [`typo3-scheduler`](#typo3-scheduler) |
| Configuration | [`typo3-config`](#typo3-config) |
| Services | [`typo3-service`](#typo3-service) |
| Overrides | [`typo3-overrides`](#typo3-overrides) |
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
vendor/bin/mate tools:call typo3-profiler-search --urlContains=/checkout --status=500
vendor/bin/mate tools:call typo3-profiler-get --token=<token>
```

Start with `-latest` for a single complaint. `typo3-profiler-search` when you already know a URL fragment or status code, `typo3-profiler-list --limit=20` to browse. Each summary carries a `resource_uri` for the full SQL detail.

</details>

## `typo3-profiler-compare` / `-triage`

Work across several recorded profiles instead of one. Both read existing profiles only and collect nothing new.

`typo3-profiler-compare` diffs two profiles (`a` as the baseline, `b` as the request after the change): timing, peak memory, query count and query time, duplicate query patterns and their executions, HTTP calls, log entries in total and by level, and included files. `largest_differences` ranks the metrics by fold change, so milliseconds and counts compete on one scale. `cache` shows hit and cacheable for both sides plus the cache-disabling reasons that appeared or went away. `contributors` names the duplicate queries, log components and events (when the profiler records them) whose numbers changed most.

`typo3-profiler-triage` looks at the most recent profiles (`limit`, default 20) and returns at most five that are worth a look, each with its reasons and token: HTTP 5xx, an exception, error-level log entries, deprecations, a duration or query count at least twice the median of the profiles considered, and a single query repeated at least ten times (N+1 suspect). Failed requests rank first, then by number of reasons, then by duration. Profiles without any reason are left out, and `_truncated` says when more were flagged than returned.

<details>
<summary>Check whether a change made a page faster</summary>

```bash
vendor/bin/mate tools:call typo3-profiler-triage
vendor/bin/mate tools:call typo3-profiler-compare --a=<token-before> --b=<token-after>
```

Take the two tokens from `typo3-profiler-search --urlContains=…` before and after the change. Read `largest_differences` first, then `contributors` for the queries behind the change.

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

`user_int_plugins` is the field to read first on a slow page: those render uncached on every request. A `url` resolves through the site router, the same way as [`typo3-route`](#typo3-route), so it reaches the page the URL actually hits.
</details>

## `typo3-records`

Read-only record query for any table (structured, parameterised — equality filters via `uid`/`pid`/`filter`, never raw SQL). Returns compact rows (uid, pid, label/type, enable columns, timestamps; long text truncated) each with a `_flags` list (hidden/deleted/timed/fe_group). No restrictions by default so hidden/deleted rows are visible — the answer to "why is this record not showing?". Pass `fields` for specific columns, `mode=full` for all columns, `respectEnableFields=true` for the frontend view. Sensitive columns (passwords and `password`-type TCA fields) are always redacted, and any embedded emails, IPv4 addresses or secrets in text values are redacted too.

<details>
<summary>Answer "why is this record not showing?"</summary>

```bash
vendor/bin/mate tools:call typo3-records --table=tt_content --pid=42
vendor/bin/mate tools:call typo3-records --table=pages --uid=42 --mode=full
vendor/bin/mate tools:call typo3-records --table=tt_content --filter=CType=text,colPos=0 --limit=10
```

No restrictions apply by default, so hidden and deleted rows are visible with a `_flags` list explaining why. Pass `--respectEnableFields=true` for the frontend view instead.

</details>

## `typo3-logs-search` / `-tail` / `-by-level`

Search, tail or filter the TYPO3 logs. Returns a compact summary (distinct messages with occurrence counts and `lastSeen`, no stack traces) by default; pass `mode=full` for individual entries with truncated traces, and `since` (e.g. `1h`, `2d`) to scope to recent entries. Emails, IPv4 addresses and secrets embedded in messages/traces are redacted before output.

<details>
<summary>Find an exception and tie it to its request</summary>

```bash
vendor/bin/mate tools:call typo3-logs-by-level --minLevel=error --since=1h
vendor/bin/mate tools:call typo3-logs-search --query="Call to a member function" --mode=full
vendor/bin/mate tools:call typo3-logs-tail --limit=20
```

A log entry's `request_id` is the profiler `token`, so it feeds straight into `typo3-profiler-get --token=…`.

</details>

## `typo3-syslog`

Backend and DataHandler events from the `sys_log` table, which the `typo3-logs-*` tools do not read: permission errors such as "Attempt to modify record tt_content:7 without permission", failed file operations and workspace errors. Filter with `error=true` (errors only, including PSR-3 rows at level `error` and above), `table`, `recordUid`, `type` (`db`, `file`, `cache`, `extension`, `error`, `site`, `setting`, `login`), `requestId` and `since`. Entries come newest first, deduplicated with a `count` and `lastSeen`, each with type, action, error level, PSR-3 `level` and `component`, table, record uid, page uid, `exampleRequestId` and the details text with its placeholders filled from `log_data`, the way the backend log module does it. Rows written by a PSR-3 `DatabaseWriter` have no details and show their `message` as stored, since that writer does not interpolate it.

User ids, IP addresses, workspace ids and the PSR-3 context `data` are never selected. Login entries and `be_users`/`fe_users` records keep their unfilled template, since their log data is a user name, and the latter report `recordUid: null`, since their uid is a user id. The filled details go through the redactor.

<details>
<summary>Find out why a save in the backend did not work</summary>

```bash
vendor/bin/mate tools:call typo3-syslog --error=true --table=tt_content --recordUid=7
vendor/bin/mate tools:call typo3-syslog --type=file --since=1h
vendor/bin/mate tools:call typo3-syslog --requestId=65f1a2b3c4d5e
```

`exampleRequestId` is the profiler `token`, as in `typo3-logs-*`, so an entry feeds straight into `typo3-profiler-get --token=…`.

The newest 1000 matching rows are grouped. `totalMatched` is the real count, and `_truncated: true` says that older rows or further distinct entries were left out.

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

## `typo3-be-permissions`

Effective permissions of a backend user (`user`) or of a group (`group`), computed the way the backend computes them: the core loads the user, resolves its groups and subgroups and merges their access lists. Returns the group chain (uids and titles), the tables allowed for select and modify, the granted exclude fields, the explicit allow/deny values, the allowed languages, the webmounts and the file mounts. `pageId` adds whether that page lies inside a webmount and the effective page permission bits (`show`, `edit`, `delete`, `new`, `editContent`). `table` adds which fields of that table the user can edit, which are exclude fields without a grant and which `TCEFORM.<table>.<field>.disabled` hides, read from the Page TSconfig of `pageId` including the user's `page.` overrides. A group is evaluated as the only group of a user that takes its mounts from its groups. Admin users are reported as `admin: true` with a hint instead of a list that would look empty. User names, real names and e-mail addresses are never returned, only uids.

<details>
<summary>Answer "why can this editor not edit field X on page Y?"</summary>

```bash
vendor/bin/mate tools:call typo3-be-permissions --user=4
vendor/bin/mate tools:call typo3-be-permissions --user=4 --table=tt_content --pageId=42
vendor/bin/mate tools:call typo3-be-permissions --group=2 --pageId=42
```

A field missing from `editableFields` is either in `excludedFields` (grant it via the group's "Allowed excludefields") or in `disabledByTsconfig` (a `TCEFORM` rule). A page with `inWebmount: false` stays invisible whatever its permission bits say.

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

## `typo3-viewhelpers`

Whether Fluid ViewHelpers such as `f:link.page` exist, which class each resolves to, and its registered arguments with type, required flag, default and description. `exists: false` is the answer, not an empty result: an unresolvable ViewHelper makes the whole template fail to parse. A miss carries the closest ViewHelper names of the same namespace as suggestions, an unknown prefix the closest global prefixes. Resolved through the runtime `ViewHelperResolver`, so global namespaces of every package and `ModifyNamespacesEvent` listeners apply.

<details>
<summary>Check a ViewHelper and its arguments before writing the tag</summary>

```bash
vendor/bin/mate tools:call typo3-viewhelpers --names=f:link.page,f:format.date
vendor/bin/mate tools:call typo3-viewhelpers --namespace=f
```

`acceptsUndeclaredArguments: true` marks a tag-based ViewHelper, which renders any argument it does not declare as a tag attribute. With `namespace` instead of `names` you get the ViewHelper names of that prefix only, derived from the classes the autoloader finds below its PHP namespaces. A namespace served by a resolver delegate cannot enumerate its ViewHelpers and is reported under `unlisted`. Only global namespaces are known; one a template declares with `xmlns` is outside the tool's reach.

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

## `typo3-labels`

Whether label references resolve to a defined key, with the default text. `exists: false` is the answer, not an empty result: an `LLL:` reference to an undefined key renders as an empty string. A missing key carries the closest keys of the same file as suggestions, a missing file the label files the extension actually has. Labels are read through the `LocalizationFactory`, so `locallangXMLOverride` overrides apply. On v14 a translation domain reference such as `core.common:cancel` works too; on v13 it is answered with a hint.

<details>
<summary>Verify label keys and their translation</summary>

```bash
vendor/bin/mate tools:call typo3-labels --references=LLL:EXT:my_ext/Resources/Private/Language/locallang.xlf:plugin.title --locale=de
vendor/bin/mate tools:call typo3-labels --extension=my_ext
```

With `locale`, each label reports `translated` and the text the locale yields. A label counts as translated when that text differs from the default one, so a translation identical to the source reads as `translated: false`. With `extension` instead of `references` you get the extension's label files with their key count, translation locales and, on v14, their domain.

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
vendor/bin/mate tools:call typo3-events --pattern=ModifyRecordListRecordActions
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

## `typo3-route`

The reverse of `typo3-site --pageId`: which page a URL hits, and why. The URL goes through the site router the frontend uses, so you get the matched site, language and page (id, slug, doktype, page type) plus the arguments a route enhancer produced, split into `staticArguments`, `dynamicArguments` and `queryArguments`. A matching `sys_redirect` entry is checked first, as the frontend does, and `outcome: redirect` means it wins over the page match. No request is sent.

<details>
<summary>Find out which page and plugin action a URL reaches</summary>

```bash
vendor/bin/mate tools:call typo3-route --url=https://example.com/news/my-article
vendor/bin/mate tools:call typo3-route --url=/products/widget
```

For an Extbase detail URL, `routeArguments` carries the plugin namespace with controller, action and the record argument, e.g. `{"tx_news_pi1": {"action": "detail", "controller": "News", "news": "5"}}`. A path without a host is tried against every configured site base in order. `outcome: no-match` is an answer, not an empty result: `reason` says whether the site, the language or the page slug did not match, and `closestBase` names the configured base nearest to the URL. Redirects are only checked when EXT:redirects is installed, which `redirectsAvailable` states. The `target` of a redirect is reported as stored, e.g. `t3://page?uid=2`. A redirect whose target is the requested URL itself carries `ignored` and does not win, since the frontend serves the page then.

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

## `typo3-scheduler`

Scheduler task status, the answer to "why did my task not run?". Lists every task with uid, type, title, description, group, last and next execution and the last failure message, each with a `flags` list: `disabled`, `group-hidden`, `failed`, `running`, `stale-running` (the running marker is older than the scheduler's `maxLifetime`, so the process is presumably dead) and `invalid` (the task can no longer be restored, with the error). `schedulerLastRun` says when the scheduler itself last ran, or `recorded: false` if it never has. `uid` returns one task with its recurrence (single or recurring, interval or cron expression, parallel execution), last execution context and failure code. Task arguments are never returned, since they often hold credentials; description and failure message are redacted. Without EXT:scheduler the tool answers `installed: false`.

<details>
<summary>Find out why a task did not run</summary>

```bash
vendor/bin/mate tools:call typo3-scheduler
vendor/bin/mate tools:call typo3-scheduler --uid=3
```

Check `schedulerLastRun` first: if the scheduler has not run for a while, no task runs, whatever its flags say. Read-only; running or editing a task is not exposed.

</details>

## `typo3-config`

`TYPO3_CONF_VARS`, feature toggles, or one extension's configuration. Because `settings.php`, `additional.php` and environment variables only produce the effective value at runtime, this is the only reliable way to read it. Secrets are masked recursively by key and remaining strings are scanned for embedded credentials; masking cannot be disabled.

<details>
<summary>Read an effective setting rather than guessing</summary>

```bash
vendor/bin/mate tools:call typo3-config --path=SYS/features
vendor/bin/mate tools:call typo3-config --section=extension --path=news
```

Omit `--path` for a compact overview, then drill in.

</details>

<details>
<summary>Find out where a value comes from</summary>

Passing `--path` also answers *where* the value comes from: `source` names the layer that set the effective value, `overrideChain` lists every layer that changed it, in order.

```bash
vendor/bin/mate tools:call typo3-config --path=FE/disableNoCacheParameter
```

```json
{
  "path": "FE/disableNoCacheParameter",
  "value": true,
  "source": "settings.php",
  "overrideChain": ["default", "settings.php"]
}
```

Three layers: `default` (TYPO3 core's own defaults), `settings.php` (`config/system/settings.php`), and `beyond-settings-php` for anything that changed the value after that: `additional.php`, an extension's `ext_localconf.php`, or other runtime code. Those three cannot be told apart without executing the installation's own PHP a second time outside TYPO3's normal boot, which this read-only tool does not do — `beyond-settings-php` says something changed it there, not which of those changed it. A value that came from an environment variable read inside `additional.php` falls into that same bucket.

</details>

## `typo3-service`

Class, constructor argument types, and public/shared flags of one DI service — why is this implementation injected, is it shared or a fresh instance per use. Public services only: TYPO3 makes most services private by default (`_defaults: public: false` in `Configuration/Services.yaml`), and this tool can only reach what the container itself exposes. Constructor arguments are the *types* PHP declares, not the *values* TYPO3 resolved: a constructor-injected service and a scalar parameter both show only their declared type. No tags and no lazy/autowired/autoconfigured flags either — both are compiler-only metadata that no longer exists once the container has compiled, and getting them back would mean rebuilding a `ContainerBuilder` the way TYPO3's own cache warmup does, which needs several `@internal` core classes and nine synthetic bootstrap instances (`ClassLoader`, `ApplicationContext`, `cache.core`, …) this tool does not reconstruct.

Unlike every other tool on this page, this one is not read-only in the strictest sense: the shared/not-shared check resolves the service (twice for a non-shared one), which really constructs it. See [Security](security.md#read-only-by-default).

<details>
<summary>Why is this implementation injected, and is it shared?</summary>

```bash
vendor/bin/mate tools:call typo3-service --id="GuzzleHttp\ClientInterface"
```

```json
{
  "id": "GuzzleHttp\\ClientInterface",
  "class": "GuzzleHttp\\Client",
  "shared": true,
  "constructorArguments": [
    {"position": 0, "name": "config", "type": "array"}
  ]
}
```

Pass a class/interface name for an autowired service, or a string service id. A service that is not registered, or that TYPO3 keeps private (the default), answers `unsupported` — console commands are the one common exception: `#[AsCommand]`'s autoconfiguration makes them public so `CommandRegistry` can fetch them by id, so this tool can also inspect this package's own commands.

</details>

## `typo3-overrides`

Every XCLASS registration in `SYS/Objects`: original class, replacement class, the class TYPO3 effectively instantiates when XCLASSes chain, and the extension that owns the replacement. It answers "why does my change to class X not take effect" without reading `typo3-config --path=SYS/Objects` and guessing which package wrote each entry.

TYPO3 does not record which `ext_localconf.php` made a registration, so the owner is the active extension the replacement class belongs to. `ownerDeterminedBy` says how that was established:

- `classFile`: the file the autoloader resolves for the replacement class lies inside the extension's path. The class itself is never loaded.
- `namespace`: the autoloader does not know the class (a typo in the registration, an extension that is not autoloaded), but its name matches a PSR-4 namespace from the extension's `composer.json`.

Both are `null` when the replacement belongs to no active extension, for example a class from a plain Composer library.

<details>
<summary>Is this class replaced, and by whom?</summary>

```bash
vendor/bin/mate tools:call typo3-overrides
vendor/bin/mate tools:call typo3-overrides --class="TYPO3\CMS\Core\Utility\File\BasicFileUtility"
```

```json
{
  "class": "TYPO3\\CMS\\Core\\Utility\\File\\BasicFileUtility",
  "found": true,
  "xclasses": [
    {
      "original": "TYPO3\\CMS\\Core\\Utility\\File\\BasicFileUtility",
      "replacement": "Vendor\\SitePackage\\Xclass\\BasicFileUtility",
      "effectiveClass": "Vendor\\SitePackage\\Xclass\\BasicFileUtility",
      "extension": "site_package",
      "package": "vendor/site-package",
      "ownerDeterminedBy": "classFile"
    }
  ]
}
```

`--class` matches the original and the replacement side, case-insensitively. `found: false` means no XCLASS touches the class.

</details>

<details>
<summary>Why DI service decorators are not listed</summary>

A decorator (`decorates:` in `Services.yaml`, `#[AsDecorator]`) replaces a service just as effectively as an XCLASS, but the running container no longer knows about it. Symfony's `DecoratorServicePass` resolves decoration at compile time: the decorated id becomes an alias of the decorator, the original definition is renamed to `<decorator id>.inner`, and the `container.decorator` tag that records the pair exists only in the `ContainerBuilder`. TYPO3 then dumps the compiled container to `var/cache/code/di/` with Symfony's `PhpDumper`, which keeps none of that metadata. Verified against TYPO3 13.4 and 14.3, which ship the same `DecoratorServicePass` and `PhpDumper`.

What survives is the list of removed private service ids, which still contains each `<decorator id>.inner`. That names the decorator but not the service it decorates, misses decorators with a custom `decoration_inner_name`, and mixes in TYPO3's own `<id>_decorated_<n>.inner` entries from service provider extensions. The decorated id only appears inside the generated factory code. Recovering the pairing would mean parsing that generated PHP or rebuilding the container, and this tool does neither.

To check a single public service, use [`typo3-service`](#typo3-service): its `class` is the decorator when the service is decorated.

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

Statically scan an extension — or all non-core extensions — against the core breaking/deprecation matchers. Returns a compact summary by default (matches grouped by message with strong/weak counts and the affected files, plus a per-origin rollup when scanning all); pass `mode=full` for individual matches with line content, and `ownOnly=true` to skip third-party (vendor) packages.

<details>
<summary>Start an LTS jump here</summary>

```bash
vendor/bin/mate tools:call typo3-extension-scanner --extension=my_ext
vendor/bin/mate tools:call typo3-extension-scanner --ownOnly=true --mode=full
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

All `typo3-*` tools are always callable — `mate`'s tool list is fixed at discovery time from the `#[MateTool]` attributes in this extension, nothing here changes it at runtime. Two clusters can still come back empty though: the profile-reading tools (`typo3-profiler-latest` / `-list` / `-search` / `-get` / `-compare` / `-triage`) until a profile exists or profiling is active, and the log search tools (`typo3-logs-search`, `typo3-logs-by-level`) until the log has entries — each answers with an honest `{"unsupported": true, "reason": "..."}` rather than an empty success.

`typo3-info` reports the current state under `toolClusters` (evaluated fresh on every call, from the filesystem) with a reason for each, so an assistant can check first instead of finding out via a wasted call.
