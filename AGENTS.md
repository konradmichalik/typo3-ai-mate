# AGENTS.md

Guidance for coding agents working in this repository.

## Project overview

`konradmichalik/typo3-ai-mate` is a dev-only TYPO3 extension and `symfony/ai-mate` bridge. It exposes the resolved runtime state of a TYPO3 installation (TCA, pages, TypoScript, middlewares, logs, request profiles) to AI coding assistants through the `symfony/ai-mate` CLI. Extension key: `typo3_ai_mate`.

- PHP: `~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0`
- TYPO3: `^13.4 || ^14.3` (core, backend, fluid, install)
- `symfony/ai-mate`: `^0.14`
- License: GPL-2.0-or-later

## Structure

```
Classes/
  Mcp/            Tool and resource classes exposed through ai-mate (scan dir in composer.json)
  Command/        TYPO3 CLI commands backing the tools
  Service/        Resolvers (Fluid, site URL, TypoScript)
  Support/        Helpers (redaction, tree building, cluster gate)
  Log/            Log processors
  Mate/           Providers and CLI runner for the Mate container
  Configuration.php
Configuration/    Services.yaml, Mate.php
Tests/
  Unit/           Unit tests (phpunit.xml)
  Functional/     Functional tests (FunctionalTests.xml)
  Acceptance/     Fixtures for the DDEV test instances
  CGL/            Separate composer project for code style, static analysis and rector
INSTRUCTIONS.md   Tool instructions shipped to assistants via extra.ai-mate.instructions
docs/             Documentation
.ddev/            DDEV setup with multi-version TYPO3 instances under .Build/
```

The `Classes/Mcp` tools are read-only views of runtime state. Application-derived output is wrapped as untrusted data, `typo3-records` redacts backend user PII and `typo3-render-page` guards against SSRF. Keep these guarantees when changing or adding tools.

## Development commands

Setup needs DDEV (see `CONTRIBUTING.md`):

```bash
composer install
ddev add-on get konradmichalik/ddev-typo3-multi-version-extension
ddev restart
ddev install all          # TYPO3 v13 and v14 under .Build/
ddev mate-setup           # register the tools in each instance
ddev mate-smoke 13        # call every tool through vendor/bin/mate (13 or 14)
```

Code quality runs in the separate `Tests/CGL` project:

```bash
composer cgl install
composer cgl lint          # composer normalize, editorconfig, PHP-CS-Fixer (dry run)
composer cgl fix
composer cgl sca           # PHPStan
composer cgl migration     # Rector
```

Release: `composer bump-version <version>` updates `composer.json`, `composer.lock` and `ext_emconf.php`. Edit `CHANGELOG.md` by hand (see `CONTRIBUTING.md`).

## Testing

```bash
composer test:unit                  # unit tests, no coverage
ddev composer test:functional       # functional tests, need a database, run inside DDEV
composer test                       # unit and functional
ddev composer test:coverage         # merged coverage in .Build/coverage (pcov)
```

- Unit tests use `phpunit.xml`, functional tests use `FunctionalTests.xml` (database defaults inside, CI overrides them).
- CI runs the tests through a reusable workflow on PHP 8.2 to 8.5, TYPO3 13.4 and 14.3, with highest and lowest dependencies.
- Add tests for every change and keep the suite green.

## Code style and static analysis

- PHP-CS-Fixer with `konradmichalik/php-cs-fixer-preset` (`Tests/CGL/.php-cs-fixer.php`).
- PHPStan at level max with `konradmichalik/phpstan-typo3-preset` and a baseline (`Tests/CGL/phpstan.neon`, `phpstan-baseline.neon`).
- Rector (`Tests/CGL/rector.php`) and `composer-dependency-analyser`.
- `.editorconfig` is enforced via `ec`. `composer.json` is normalized with `ergebnis/composer-normalize`.
- CI runs CGL, the security workflow and the tests through reusable workflows.

## Git workflow

- Commit format: `<type>: <description>`
- Types: `feat`, `fix`, `refactor`, `docs`, `test`, `chore`, `perf`, `ci`
- Release commits use `release: version <x.y.z>` and go in their own pull request.
- No co-author trailers
- One commit per logical change
- Create a feature branch and open a pull request with a clear description.
