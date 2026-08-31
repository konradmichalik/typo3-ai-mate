# Connecting an assistant

`vendor/bin/typo3 typo3-ai-mate:install` runs `mate init` and `mate discover`, and nothing else. It exists so `composer require`/`composer update` has one command to point at. `--dry-run` reports every planned step without running anything.

There is no server process. An assistant calls a tool by running a shell command:

```bash
vendor/bin/mate tools:call typo3-tca --table=tt_content
```

## What gets written

`mate discover` aggregates the instructions of every installed Mate extension (this one contributes `INSTRUCTIONS.md`) into `mate/AGENT_INSTRUCTIONS.md`, which documents every tool and its parameters. It then maintains a managed block pointing at that file:

- `AGENTS.md` gets a `<!-- BEGIN AI_MATE_INSTRUCTIONS --> … <!-- END AI_MATE_INSTRUCTIONS -->` block.
- `CLAUDE.md` gets an `@AGENTS.md` import, because Claude Code reads `CLAUDE.md` and not `AGENTS.md`.

Any assistant that reads only its own client-specific file, such as `.cursor/rules`, needs the same kind of import added by hand.

Re-run the install command after every `composer update`, so changed tool descriptions and parameters reach `mate/AGENT_INSTRUCTIONS.md` before the next session.

## Agent Skills

`mate discover` also installs the Agent Skills that installed Mate extensions ship, as real copies under `.agents/skills/mate-<name>/`, with relative symlinks mirrored into `.claude/skills/`. They come from `symfony/ai-mate`, not from this extension.

The generated folders are deliberately not gitignored: because they are plain copies, committing them turns an upstream skill change into a reviewable diff.

```bash
vendor/bin/mate skills:list          # what is installed, and its state
vendor/bin/mate skills:disable <name>  # switch one off
vendor/bin/mate skills:prune           # remove folders no skill claims any more
```

## Why there is no MCP server any more

> [!NOTE]
> This was an upstream decision in `symfony/ai-mate` 0.13, not one this extension made. The reasoning is worth knowing, because it explains why the agent instructions above matter so much more than they used to.
>
> Mate previously shipped as an MCP server. Upstream replaced it with a plain CLI **plus a discovery layer**, and argued that the two only work together: in their controlled comparison the bare CLI was invoked **0 out of 30 times**. It was installed, working and invisible, performing no better than not having Mate at all. With the materialised agent instructions and skills on top, the same CLI became clearly effective.
>
> Upstream states plainly that this was one task family with small samples and should be read as a direction rather than as figures to quote. Taken as a direction it is the important part for you: the CLI is not self-advertising the way an MCP tool list was. If the managed `AGENTS.md`/`CLAUDE.md` block does not reach your assistant, the tools exist and nothing will call them.

## Upgrading from 0.4 or earlier

Version 0.5.0 followed `symfony/ai-mate` 0.13 in removing the MCP server. Two leftovers need attention, because nothing removes them for you:

1. **Delete the `typo3-ai-mate` entry from `.mcp.json`** (and `opencode.json`, if present). It launches `mate serve`, which no longer exists, so an assistant that still tries to start it reports a broken MCP server.
2. `bin/codex` and `bin/codex.bat` may be left over from an earlier `mate init`. Those Codex CLI launcher shims are no longer written and can be deleted.

Tool responses also changed shape: output captured from the installation is now nested under an `untrusted_data` key. Anything parsing tool output has to unwrap one level. See [Security](security.md#untrusted-data).
