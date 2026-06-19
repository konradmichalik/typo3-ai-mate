<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Mcp;

use KonradMichalik\Typo3AiMate\Mate\Typo3CliRunner;
use KonradMichalik\Typo3AiMate\Mcp\Enum\{LogLevel, OutputMode};
use Mcp\Capability\Attribute\McpTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * LogsTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class LogsTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string|null   $query     full-text needle matched against the log message; omit to match every entry
     * @param LogLevel|null $level     restrict to a single severity; omit for all levels
     * @param string|null   $component Restrict to a log component/channel, dotted (e.g. TYPO3.CMS.Frontend); omit for all.
     * @param string|null   $requestId correlate with a profiler token (= request_id) to tie an error back to its request; omit for all
     * @param int           $limit     maximum number of results to return
     * @param OutputMode    $mode      summary (default, distinct messages grouped with counts) | full (individual entries with truncated traces)
     * @param string|null   $since     Relative time window, e.g. 1h, 2d; omit for all entries regardless of age.
     */
    #[McpTool(name: 'typo3-logs-search', title: 'TYPO3 Log Search', description: 'Full-text search the TYPO3 logs with optional level/component/request-id filters. Returns a compact summary (distinct messages with occurrence counts and lastSeen, no stack traces) by default; pass mode=full for individual entries with truncated traces. Use since (e.g. 1h, 2d) to scope to recent entries. Use this when you have a search term; filter by requestId to tie an error back to its profiler token (= request_id).')]
    public function search(
        ?string $query = null,
        ?LogLevel $level = null,
        ?string $component = null,
        ?string $requestId = null,
        int $limit = 50,
        OutputMode $mode = OutputMode::Summary,
        ?string $since = null,
    ): string {
        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:logs:search', [], $this->options([
            'query' => $query,
            'level' => $level?->value,
            'component' => $component,
            'request-id' => $requestId,
            'limit' => $limit,
            'format' => $mode->value,
            'since' => $since,
        ])));
    }

    /**
     * @param int         $limit number of most recent entries to return
     * @param OutputMode  $mode  summary (default, distinct messages grouped with counts) | full (individual entries with truncated traces)
     * @param string|null $since Relative time window, e.g. 1h, 2d; omit for all entries regardless of age.
     */
    #[McpTool(name: 'typo3-logs-tail', title: 'TYPO3 Log Tail', description: 'Return the most recent TYPO3 log entries. Defaults to a compact summary (distinct messages with counts); pass mode=full for individual entries with truncated traces. Use this when you want the last N entries regardless of severity or text.')]
    public function tail(int $limit = 50, OutputMode $mode = OutputMode::Summary, ?string $since = null): string
    {
        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:logs:search', [], $this->options([
            'limit' => $limit,
            'format' => $mode->value,
            'since' => $since,
        ])));
    }

    /**
     * @param LogLevel    $level     Minimum severity; entries at or above this level are returned (e.g. error also yields critical, alert, emergency).
     * @param string|null $requestId correlate with a profiler token (= request_id) to tie an error back to its request; omit for all
     * @param int         $limit     maximum number of results to return
     * @param OutputMode  $mode      summary (default, distinct messages grouped with counts) | full (individual entries with truncated traces)
     * @param string|null $since     Relative time window, e.g. 1h, 2d; omit for all entries regardless of age.
     */
    #[McpTool(name: 'typo3-logs-by-level', title: 'TYPO3 Logs by Level', description: 'Return TYPO3 log entries at or above a minimum severity (e.g. error), optionally filtered by request-id. Defaults to a compact summary (distinct messages with counts and lastSeen, no stack traces); pass mode=full for individual entries with truncated traces. Use since (e.g. 1h, 2d) to scope to recent entries. Use this when you want every entry from a severity upwards regardless of message text; filter by requestId to tie an error back to its profiler token (= request_id).')]
    public function byLevel(LogLevel $level, ?string $requestId = null, int $limit = 50, OutputMode $mode = OutputMode::Summary, ?string $since = null): string
    {
        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:logs:search', [], $this->options([
            'level' => $level->value,
            'request-id' => $requestId,
            'limit' => $limit,
            'format' => $mode->value,
            'since' => $since,
        ])));
    }

    /**
     * @param array<string, scalar|null> $options
     *
     * @return array<string, scalar>
     */
    private function options(array $options): array
    {
        return array_filter($options, static fn (mixed $value): bool => null !== $value && '' !== $value);
    }
}
