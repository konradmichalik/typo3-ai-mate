# Tool surface and context cost

Why the tool count is what it is, and what it costs a session. Kept because the question recurs and the answer is measured rather than argued.

Growth of the tool surface was measured, not estimated, when the question first came up (issue #71): the tool definitions (attribute description, docblocks, parameter signatures) plus `INSTRUCTIONS.md` landed at roughly 6,000–8,000 tokens per session, near four percent of a 200k context window. `typo3-records` and `typo3-logs-search` were the two heaviest single definitions before a trim removed sentences that only restated what their own parameter docblocks already said — the client receives both, so repeating it in the top-level description was pure overhead, not extra information. That measurement predates the migration to ai-mate v0.13's native `#[MateTool]` attribute, which dropped `outputSchema`/`annotations` entirely (no home for them anymore). It has since been re-measured, see below.

**Re-measured on ai-mate v0.13 (September 2026).** Byte counts are exact, taken against TYPO3 14.3.6 / PHP 8.5 on one local installation; token figures divide by four and are therefore approximate.

The v0.13 CLI model changed what the number even means. Under the MCP server every tool definition was pushed into the session before the first message. Now schemas are read on demand, so only the materialized instructions are unavoidable:

| Loaded | What | Bytes | ~Tokens |
| --- | --- | --- | --- |
| Up front, always | `mate/AGENT_INSTRUCTIONS.md` (mate's 1,118 B header plus this package's `INSTRUCTIONS.md`) | 13,619 | 3,400 |
| On demand | `tools:list`, default table | 5,602 | 1,400 |
| On demand | `tools:inspect <tool> --format=toon`, one tool | 1,809 | 450 |
| Upper bound | all 32 definitions (14,268 B of descriptions plus ~6,400 B of parameter docblocks) | 20,682 | 5,200 |

The session floor is therefore around 3,400 tokens, not 6,000 to 8,000, and the full definition surface is only reached by an agent that inspects every tool, which nothing asks it to do.

**The output format now costs more than the tool count.** `tools:call` renders its default `pretty` format by echoing the tool's whole description back and padding the result into an ASCII table sized to the security notice. Same tool, same answer:

| Format | `typo3-middlewares` | `tools:inspect typo3-tca` |
| --- | --- | --- |
| default (`pretty` / `text`) | 11,136 B | 5,745 B |
| `--format=json` | 4,504 B | 2,333 B |
| `--format=toon` | 2,624 B | 1,809 B |

At a dozen calls a session that is the difference between roughly 8,000 and 20,000 tokens, which dwarfs the entire definition surface above. `INSTRUCTIONS.md` therefore opens by requiring `--format=toon`. TOON is also what `ToolResult` already encodes; `ToolsCallCommand` decodes it and re-encodes per `--format`, so the tool-side encoding alone changes nothing about what the agent receives.

`tools:list` inverts this and is documented as the exception: its default table truncates descriptions to what routing needs (5,602 B), while `--format=toon` and `--format=json` dump all 32 full descriptions at 35,191 B and 52,177 B. A blanket "always pass a machine format" instruction would have made the listing six times more expensive.

**Conclusion: context size is not a reason to remove tools.** Under two percent of the window does not justify deleting working functionality. Merging tools does not delete their descriptions either — it relocates them into parameter documentation, saving perhaps 30–40 percent of a merged block, not 75 percent ("four tools become one, so a quarter of the cost" does not hold up). If context were the actual goal, the lever is prose discipline, as applied above to `typo3-records`/`typo3-logs-search`, not tool count.

**The actual problem is routing, not size.** Seven tools carry the `profiler` prefix and three carry `logs` — ten entries for two concepts, with near-synonymous names inside each cluster, most notably the four profiler read tools (`typo3-profiler-latest`/`-list`/`-search`/`-get`). An assistant given "this page is slow" has to disambiguate between them without help from the names alone. The other tools are each a distinct, self-explanatory concept; the marginal cost of adding another one there (`typo3-changelog-search`, `typo3-site`, `typo3-db-schema`, …) is close to zero because it competes with nothing.

**Decision: disambiguate, don't consolidate.** Every tool in `PerformanceTool`, `ProfilerControlTool` and `LogsTool` now carries an explicit "use when" clause distinguishing it from its siblings, and `typo3-info` is documented as the entry point in `INSTRUCTIONS.md`'s "Start here" section. Consolidation is deferred, not ruled out: if routing quality still disappoints after this disambiguation pass, the profiler read quartet (`-latest`/`-list`/`-search`/`-get`) is the one genuine merge candidate, and it belongs in a 1.0 alongside other breaking changes, not a patch.

The claim that selection quality degrades past a specific tool count is a rule of thumb, not a measured property — treat the count as one input, not a threshold. Session context is also not this package's alone; a user with several Mate extensions (or other tools) installed can pass 40k tokens of instructions before typing anything. At a ~3,400-token floor this package is a reasonable citizen, and the lever for staying one is description discipline and a compact output format, not tool removal.
