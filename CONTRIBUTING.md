# Contributing

Thank you for considering contributing to this project! Every contribution is welcome and helps improve the quality of the project.

Please note that this project adheres to the [TYPO3 Code of Conduct](https://typo3.org/community/values/code-of-conduct). By participating, you are expected to uphold this code.

## Requirements

- [DDEV](https://ddev.readthedocs.io/en/stable/)

## Preparation

```bash
# Clone repository
git clone https://github.com/konradmichalik/typo3-ai-mate.git
cd typo3-ai-mate

# Install dependencies
composer install

# Set up the multi-version test environment
ddev add-on get konradmichalik/ddev-typo3-multi-version-extension
ddev restart
ddev install all
```

`ddev install all` provisions TYPO3 v13 and v14 under `.Build/`, symlinks this
extension and the demo sitepackage, and imports `Tests/Acceptance/Fixtures/data.xml`.

## Exercising the request profiler flow

The `typo3-profiler-*` MCP tools read the profiles written by
[`konradmichalik/typo3-request-profiler`](https://packagist.org/packages/konradmichalik/typo3-request-profiler),
which is a dependency of this extension — so `ddev install all` already provides
it. Just trigger a request to record a profile:

```bash
ddev all typo3 cache:flush

# Trigger the N+1 demo page so a profile is recorded (Development context)
ddev launch 13 /
ddev launch 14 /

# A profile now exists under .Build/<version>/var/log/profiles/{token}.json and is
# served by the typo3-profiler-* tools. Discover and call them via mate:
ddev 13 ./vendor/bin/mate discover
ddev 13 ./vendor/bin/mate mcp:tools:call typo3-profiler-latest
```

## Inspecting the MCP protocol layer

`ddev mcp-inspect` wraps the [MCP Inspector](https://modelcontextprotocol.io/docs/tools/inspector)
and connects it to `vendor/bin/mate serve` (stdio transport) inside the chosen
instance. This validates the real protocol — the `initialize` handshake, the tool
JSON-schemas as an assistant sees them, and that stdout stays clean — which the
`mate mcp:tools:*` commands bypass.

```bash
# Interactive browser UI against v13 (default), or pass 14
ddev mcp-inspect 13

# Headless checks (CLI mode)
ddev mcp-inspect 13 --cli --method tools/list
ddev mcp-inspect 13 --cli --method tools/call --tool-name typo3-tca --tool-arg table=tt_content
```

The command intentionally connects via `ddev exec` rather than the `ddev <version>`
wrapper: the wrapper prints a `[TYPO3 v<n>] …` header that would corrupt the stdio
MCP stream.

To verify **all** tools at once, `ddev mcp-smoke [13|14]` calls every tool over the
protocol and prints a pass/fail summary (exit code non-zero if any fail):

```bash
ddev mcp-smoke 13
#   ✔ typo3-tca
#   …
#   12 passed, 0 failed
```

The `typo3-profiler-*` tools only pass once the profiler is installed and a request
has been recorded (see above).

## Run tests & checks

```bash
# Unit tests
composer test

# Coding standards, static analysis, rector (CGL)
composer cgl install
composer cgl lint
composer cgl sca
composer cgl migration
```

## Pull requests

1. Create a feature branch.
2. Add tests for your change and keep the existing suite green.
3. Run the CGL checks (`composer cgl lint` / `composer cgl sca`).
4. Open a pull request with a clear description.
