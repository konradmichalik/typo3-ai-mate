# Security

> [!WARNING]
> All tools operate on the **local installation only** and must never be exposed over a network. [ai-mate](https://symfony.com/doc/current/ai/components/mate.html) redacts cookies, auth headers and secrets by default.

Every command runs only in a Development context (`Environment::getContext()->isDevelopment()`). No tool executes arbitrary code, and none expose a raw SQL surface: `typo3-records` is a structured, parameterised query with equality filters only, never a `SELECT` string.

## Read-only by default

Only 3 of the 42 tools mutate anything:

- `typo3-profiler-start` / `-stop` change profiler control state, and only as a time-boxed dev switch. They touch no records.
- `typo3-render-page` issues a real internal HTTP request, so it has side effects in caches and logs.

`typo3-service` mutates nothing persisted either, but is worth naming separately: determining whether a service is shared resolves it through the container, twice for a non-shared one, which really constructs the object. A well-behaved service's constructor does no I/O, but this tool has no way to confirm that in advance, unlike the read-only guarantee every other tool here can make from static inspection alone.

## Guards

- **Redacts PII and secrets** (`Support\Redactor`) — emails, IPv4 addresses and `key=value`/`key: value` secrets (`password`, `token`, `api_key`, `authorization`, …) are stripped from logs, TypoScript/TSconfig dumps, resolved records and profiler data before they reach the AI client.
- **Keeps user identities out of `typo3-syslog`**: `sys_log` stores a user id, an IP address and a workspace id with every row, and PSR-3 rows a context `data` blob. None of these columns is ever selected. Login entries and `be_users`/`fe_users` records keep their unfilled details template, because their log data is a user name. All other details, and the PSR-3 message shown in their place, are filled from `log_data` where applicable and then redacted like log messages.
- **Restricts `typo3-render-page` to configured site hosts** — an absolute `--url` is rejected unless its host matches one of the installation's site bases, and redirects are not followed, closing an SSRF vector against internal and cloud-metadata endpoints.
- **Never returns scheduler task arguments**: `typo3-scheduler` picks the fields it reports by name, so a task's arguments and the backend module's summary of them (`getAdditionalInformation()`), where API keys and passwords for scheduled commands end up, never reach the output. The description and failure message it does report go through `Support\Redactor`. It reads through the core's task listing and avoids the repository and scheduler calls that disable broken tasks or clear stale running markers.
- **Validates profile tokens** — `typo3-profiler-get`, `typo3-profiler-compare` and the `typo3-profiler://profile/{token}` resource only accept alphanumeric tokens, rejecting path traversal before the file-based lookup.
- **Hardens CLI argument passing** — `Typo3CliRunner` inserts an end-of-options `--` separator before positional arguments, so a value that happens to start with `-`/`--` (e.g. a table or page id from the assistant) can never be parsed as an option of the target command.
- **Caps how long an agent can leave profiling on** — `typo3-profiler-start` rejects durations above 60 minutes (the profiler itself permits up to 7 days), so a looping agent cannot turn a dev machine into a permanently profiled one. Writes go through the profiler's `profiler:activate`/`:deactivate` commands, keeping atomic write and file permissions in the package that owns the state file.
- **Returns no personal data from `typo3-be-permissions`**: the output is built from an allowlist of uids, access lists and group titles, so user names, real names, e-mail addresses and every other column of `be_users` never reach the client. Group titles go through `Support\Redactor` like other free text. Loading the user is read-only too: the core would otherwise persist a corrected workspace to `be_users` and log that to `sys_log` when the stored one is invalid, and the tool suppresses exactly that write.

`ddev mate-smoke` asserts the first three of these against a live installation, so a regression fails a command rather than a reviewer's attention. See [`CONTRIBUTING.md`](../CONTRIBUTING.md).

> [!NOTE]
> Profiles carry request URLs, SQL, timings and configuration values. URLs and SQL are redacted as described above, but everything else is exposed as recorded, so enabling the profiling tools makes that data readable by any assistant with access to this installation.

## Untrusted data

Tool output captured from the inspected application (records, TCA labels, logs, `sys_log` details, rendered pages, profiler data, site and icon identifiers, extension keys) is wrapped by `Symfony\AI\Mate\Encoding\ResponseEncoder::encodeUntrusted()`. It nests the payload under an `untrusted_data` key alongside a `_security_notice` telling the model to treat the content strictly as data, never as instructions. That data is frequently authored by editors or third-party extensions, and is exactly the kind of content a prompt-injection attempt would try to plant.

`Classes/Mate/ToolResult.php` exposes both `from()` (plain `ResponseEncoder::encode()`) and `untrusted()` (`encodeUntrusted()`). Use `untrusted()` whenever the payload includes text or identifiers an editor or a third-party extension could have authored. Use `from()` only for output this package itself computed, with nothing pulled from the inspected application's own data or third-party code. When unsure, prefer `untrusted()`: wrapping data that turned out to be harmless costs nothing, the reverse does not.

In practice that threshold is high. `ProfilerControlTool` is the only tool that qualifies for `from()`, because activation state is the one thing here computed entirely by this package. Anything that reads the installation, including a bare list of registered icon identifiers or the installed extension keys in `typo3-info`, carries strings some third-party package chose.
