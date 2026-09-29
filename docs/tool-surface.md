# Tool surface and context cost

Why the tool count is what it is, and what it costs a session. Kept because the question recurs and the answer is measured rather than argued.

Growth of the tool surface was measured, not estimated, when the question first came up (issue #71): the tool definitions (attribute description, docblocks, parameter signatures) plus `INSTRUCTIONS.md` landed at roughly 6,000–8,000 tokens per session, near four percent of a 200k context window. `typo3-records` and `typo3-logs-search` were the two heaviest single definitions before a trim removed sentences that only restated what their own parameter docblocks already said — the client receives both, so repeating it in the top-level description was pure overhead, not extra information. That measurement predates the migration to ai-mate v0.13's native `#[MateTool]` attribute, which dropped `outputSchema`/`annotations` entirely (no home for them anymore). It has since been re-measured, see below.

**Re-measured on ai-mate v0.14 (September 2026), superseding the v0.13 numbers below.** Byte counts are exact, taken against TYPO3 14.3.6 / PHP 8.5 on one local installation; token figures divide by four and are therefore approximate. The v0.13 figures are kept struck through where a number changed, because the delta is itself the finding: v0.14's `tools:list` and `tools:call` changes (symfony/ai#2488, #2584, #2585) moved by nearly an order of magnitude in both directions.

The CLI model (introduced in v0.13) changed what the number even means. Under the old MCP server every tool definition was pushed into the session before the first message. Now schemas are read on demand, so only the materialized instructions are unavoidable:

| Loaded | What | Bytes | ~Tokens |
| --- | --- | --- | --- |
| Up front, always | `mate/AGENT_INSTRUCTIONS.md` (mate's header plus this package's `INSTRUCTIONS.md`) | 13,807 | 3,450 |
| On demand | `tools:list`, default table | ~~5,602~~ 57,203 | ~~1,400~~ 14,300 |
| On demand | `tools:list --format=toon` | 36,159 | 9,000 |
| On demand | `tools:inspect <tool> --format=toon`, one tool | 1,830 | 460 |

The session floor is still around 3,450 tokens. What changed is the cost of *not* picking a tool by name: `tools:list` (symfony/ai#2488) now embeds each tool's arguments in the same table, and that table is not the one v0.14 un-padded (see below), so its default rendering grew roughly tenfold. It is the single most expensive way to see the tool catalogue, more expensive even than `--format=json` (53,265 B). `INSTRUCTIONS.md`'s own "Which tool for which question" table exists precisely so an agent never has to pay this: read the tool surface once, and it does not need to be paid again this session.

**The output format still matters, but the shape of the cost changed.** `tools:call`'s default `pretty` format no longer pads the whole answer into an ASCII table (symfony/ai#2585); large results now auto-fall back to indented JSON instead (symfony/ai#2584). Same tool, same answer:

| Format | `typo3-middlewares` (small) | `typo3-tca --table=tt_content` (large, trips the JSON fallback) |
| --- | --- | --- |
| default (`pretty` / `text`) | ~~11,136~~ 3,238 B | 28,766 B |
| `--format=json` | 4,504 B | 22,140 B |
| `--format=toon` | 2,624 B | 13,522 B |

For a small result, `pretty` is now close to `toon` and no longer the outlier it was in v0.13. For a large result it still is, just for a different reason: the fallback is pretty-printed JSON, over twice `toon`'s size. `INSTRUCTIONS.md` still requires `--format=toon` unconditionally, since which tools return "small" answers is not something to reason about per call, and it is never worse than the alternative. TOON is also what `ToolResult` already encodes; `ToolsCallCommand` decodes it and re-encodes per `--format`, so the tool-side encoding alone changes nothing about what the agent receives.

**Conclusion: context size is not a reason to remove tools.** Under two percent of the window does not justify deleting working functionality. Merging tools does not delete their descriptions either — it relocates them into parameter documentation, saving perhaps 30–40 percent of a merged block, not 75 percent ("four tools become one, so a quarter of the cost" does not hold up). If context were the actual goal, the lever is prose discipline, as applied above to `typo3-records`/`typo3-logs-search`, not tool count.

**The actual problem is routing, not size.** Seven tools carry the `profiler` prefix and three carry `logs` — ten entries for two concepts, with near-synonymous names inside each cluster, most notably the four profiler read tools (`typo3-profiler-latest`/`-list`/`-search`/`-get`). An assistant given "this page is slow" has to disambiguate between them without help from the names alone. The other tools are each a distinct, self-explanatory concept; the marginal cost of adding another one there (`typo3-changelog-search`, `typo3-site`, `typo3-db-schema`, …) is close to zero because it competes with nothing.

**Decision: disambiguate, don't consolidate.** Every tool in `PerformanceTool`, `ProfilerControlTool` and `LogsTool` now carries an explicit "use when" clause distinguishing it from its siblings, and `typo3-info` is documented as the entry point in `INSTRUCTIONS.md`'s "Start here" section. Consolidation is deferred, not ruled out: if routing quality still disappoints after this disambiguation pass, the profiler read quartet (`-latest`/`-list`/`-search`/`-get`) is the one genuine merge candidate, and it belongs in a 1.0 alongside other breaking changes, not a patch.

The claim that selection quality degrades past a specific tool count is a rule of thumb, not a measured property — treat the count as one input, not a threshold. Session context is also not this package's alone; a user with several Mate extensions (or other tools) installed can pass 40k tokens of instructions before typing anything. At a ~3,400-token floor this package is a reasonable citizen, and the lever for staying one is description discipline and a compact output format, not tool removal.
