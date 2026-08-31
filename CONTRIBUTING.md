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

# Register the typo3-* tools in each instance (composer plugin + mate discover)
ddev mate-setup
```

`ddev install all` provisions TYPO3 v13 and v14 under `.Build/`, symlinks this
extension and the demo sitepackage, and imports `Tests/Acceptance/Fixtures/data.xml`.

`ddev mate-setup` then allows the ai-mate composer plugin and runs
`mate init` / `mate discover` in each instance, so `mate tools:call` (and the
`mate-smoke` command) find the tools. Re-run it after a fresh
`ddev install`, since the add-on rebuilds the instance from scratch.

## Exercising the request profiler flow

The `typo3-profiler-*` tools read the profiles written by
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
ddev 13 ./vendor/bin/mate tools:call typo3-profiler-latest
```

## Smoke-testing the whole tool surface

`ddev mate-smoke [13|14]` calls every tool through `vendor/bin/mate tools:call`
and prints a pass/fail summary (exit code non-zero if any fail):

```bash
ddev mate-smoke 13
#   ✔ typo3-tca
#   …
#   21 passed, 0 failed
```

A tool passes when the CLI exits cleanly **and** the payload is not an honest miss
(`"unsupported": true`). That is stricter than it looks: the unit and functional
suites construct tool classes directly, so they cannot catch a tool that is
discovered but not resolvable from the Mate container. This harness can, because
it goes through the real CLI.

On top of the sweep it asserts behaviour that must not regress silently: the
`typo3-render-page` SSRF guard rejecting a cloud-metadata host, `typo3-profiler-get`
refusing a traversal-shaped token without leaking file content, `typo3-records`
redacting `be_users` PII, and application-derived output arriving inside the
`untrusted_data` envelope.

The `typo3-profiler-*` tools only pass once the profiler is installed and a request
has been recorded (see above).

> [!NOTE]
> There is no MCP protocol layer to inspect any more. `symfony/ai-mate` 0.13
> removed the server (`mate serve`) in favour of a plain CLI, so the former
> `ddev mcp-inspect` command and its MCP Inspector wrapper are gone.

## Run tests & checks

```bash
# Unit tests
composer test

# Functional tests (need a database; run inside DDEV).
# The typo3Database* defaults live in FunctionalTests.xml; CI overrides them.
ddev composer test:functional

# Unit + functional tests with merged coverage (clover + HTML in .Build/coverage)
ddev composer test:coverage

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

## Releasing

A release is its own pull request, cut from `main` after the feature pull requests it contains have been merged.

```bash
# 1. Bump the version in composer.json, composer.lock and ext_emconf.php
composer bump-version 0.5.0
```

`composer bump-version` covers only those three files, so two `CHANGELOG.md` edits stay manual:

2. Rename the `## [Unreleased]` heading to `## [0.5.0] - YYYY-MM-DD`.
3. At the bottom, point `[Unreleased]` at the new tag and add a `[0.5.0]` compare link:

```markdown
[Unreleased]: https://github.com/konradmichalik/typo3-ai-mate/compare/0.5.0...HEAD
[0.5.0]: https://github.com/konradmichalik/typo3-ai-mate/compare/0.4.0...0.5.0
```

The heading cannot be automated through `version-bumper.yaml`: a `filesToModify` pattern replaces text that already contains a version, and the literal word `Unreleased` is not version-shaped.

4. Commit as `release: version 0.5.0`, open the pull request, merge it, then tag and release from the merge commit.
