# Sitepackage (N+1 / exception demo)

Demo sitepackage used to exercise `typo3-ai-mate` (and `typo3-request-profiler`)
in the multi-version DDEV test environment.

It provides two `USER_INT` content elements so the N+1 and the exception are
attributable to a concrete `tt_content` element — which is what the MCP tools
report:

- **Page 1 "N+1 Demo"** holds a `sitepackage_nplusone` element. It is rendered
  by `NplusOneDemoRenderer` as `USER_INT` (uncached), running one `SELECT … WHERE
  uid = N` **per iteration** — a classic N+1. After loading the page in a
  Development context, the profile under `var/log/profiles/{request_id}.json`
  lists the repeated query in `duplicate_queries` (`count > 1`) and reports
  `cache.cacheable = false`.
- **Page 2 "Exception Demo"** holds a `sitepackage_throws` element rendered by
  `ExceptionRenderer`, which throws on every request so the exception appears in
  the TYPO3 log.

## What it validates

| Tool | Expectation |
|---|---|
| `typo3-profiler-latest` | N+1 query in `duplicate_queries`, correct `page.id`, `cache.hit=false` |
| `typo3-page` (page 1) | lists the `sitepackage_nplusone` element and marks it as a USER_INT plugin |
| `typo3-logs-search` (page 2) | returns the thrown exception, filterable by `request-id` |

## Import

Import `Tests/Acceptance/Fixtures/data.xml` (T3D / impexp format) into the test
instance, e.g. `vendor/bin/typo3 impexp:import data.xml`. A site with root page
id `1` must exist (created by the DDEV environment).
