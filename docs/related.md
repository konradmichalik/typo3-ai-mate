# Related projects

Several projects connect AI assistants to TYPO3, and they answer different questions. This page is here so you can tell which one you actually want, and because more than one of them is often the right answer.

> [!NOTE]
> Two different projects are called `typo3-mcp-server`, by different authors. They are not versions of each other. Check the vendor prefix before you require one.

| Project | Answers | Writes | Intended for |
| --- | --- | --- | --- |
| **`konradmichalik/typo3-ai-mate`** (this one) | The resolved runtime state of *your* installation: merged TCA, resolved TypoScript, PSR-15 order, logs, request profiles | No, except a time-boxed profiler toggle | Development context only, dev dependency |
| [`TYPO3/dev-companion`](https://github.com/TYPO3/dev-companion) | Curated, version-bound TYPO3 knowledge for 12.4, 13.4, 14.3 and main, task workflows as Agent Skills, and lookups into the installation it starts in (configuration, DI services, TCA-derived schema, records, icons, labels, FlexForms, backend modules, what an extension registers) | No, nothing is written into the installation | Coding agents implementing and reviewing TYPO3 work |
| [`froemken/changelog-mcp`](https://github.com/froemken/changelog-mcp) | The TYPO3 core changelogs, imported into the database and served as Markdown | No | Local LLMs and air-gapped setups without TYPO3 training data |
| [`hauptsacheNet/typo3-mcp-server`](https://github.com/hauptsacheNet/typo3-mcp-server) | Content operations: create, edit and translate | Yes, gated behind workspaces for review before publish | Editorial work with a human in the loop |
| [`marekskopal/typo3-mcp-server`](https://github.com/marekskopal/typo3-mcp-server) | 60+ administration tools for pages, content, files, backend users and extension records | Yes, and by design immediately, with no approval queue; workspaces are a secondary mode | Fully autonomous agents building and maintaining sites |
| [`nitsan-technologies/ns_t3af`](https://github.com/nitsan-technologies/ns_t3af) | An MCP endpoint with content, SEO, translation and media tools, plus LLM provider, prompt, permission and budget management in a backend module | Yes | Site operators connecting agents and backend AI features to a live site |

## How to choose

**Knowledge or state?** `dev-companion` tells you what TYPO3 says, bound to a version, and has grown lookups into the installation it runs in, so the two now overlap on registry facts such as icons, labels, FlexForm data structures and configuration values. What stays with this extension is the state that only exists per page or per request: the TypoScript and TSconfig resolved along one rootline, the Fluid override chain that wins for one plugin, the PSR-15 order, the deprecations and log entries a real request fired, and the request profiles that tie them together. Its output from the application is redacted where it can carry secrets or personal data and wrapped as untrusted data. The two sit side by side well, and both read only.

**Reading or writing?** `dev-companion`, `changelog-mcp` and this extension read. The others write, and they differ sharply in how much they ask first: `hauptsacheNet` routes changes through workspaces so someone reviews before publish, while `marekskopal` states plainly that direct, immediately-effective operation is the primary mode and workspaces the fallback. `ns_t3af` is the broadest of them and also manages which LLM providers the backend talks to. Pick according to how much autonomy you are willing to hand over, not according to which has more tools.

**This extension deliberately writes no content.** Its only write is the profiling toggle (`typo3-profiler-start`/`-stop`), a time-boxed dev switch that touches no records. If you want an assistant to edit your site, one of the writing projects is the right tool, and this one can still sit next to it to diagnose what came out.

## Other ai-mate bridges

This extension follows the pattern `symfony/ai-mate` sets for framework bridges: a dev dependency, tools that read the booted application, and instructions shipped through `extra.ai-mate`. [`Sylius/sylius-mate-extension`](https://github.com/Sylius/sylius-mate-extension) does the same for Sylius and is a useful comparison when designing a new tool.

## Measurement

`benjaminkott/typo3-mcp-bench` measures these tools against a control agent that has the same model and file access but no server, on a shared case suite. It is the reason several decisions in this extension are recorded with numbers rather than intuition, and `dev-companion` is one of the arms it runs.

Treat published figures with care, including ours: the committed runs are one repetition per arm, and the benchmark itself refuses to pool runs that differ in cases, fixtures or targets. Our own re-baseline against the 0.5.0 CLI architecture is tracked in [#114](https://github.com/konradmichalik/typo3-ai-mate/issues/114).
