<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <km@move-elevator.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Mcp;

use KonradMichalik\Typo3AiMate\Mate\Typo3CliRunner;
use Mcp\Capability\Attribute\McpTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * LogsTool.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final readonly class LogsTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    #[McpTool(name: 'typo3-logs-search', title: 'TYPO3 Log Search', description: 'Full-text search the TYPO3 logs with optional level/component/request-id filters. Returns a compact summary (distinct messages with occurrence counts and lastSeen, no stack traces) by default; pass mode=full for individual entries with truncated traces. Use since (e.g. 1h, 2d) to scope to recent entries.')]
    public function search(
        ?string $query = null,
        ?string $level = null,
        ?string $component = null,
        ?string $requestId = null,
        int $limit = 50,
        string $mode = 'summary',
        ?string $since = null,
    ): string {
        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:logs:search', [], $this->options([
            'query' => $query,
            'level' => $level,
            'component' => $component,
            'request-id' => $requestId,
            'limit' => $limit,
            'format' => $mode,
            'since' => $since,
        ])));
    }

    #[McpTool(name: 'typo3-logs-tail', title: 'TYPO3 Log Tail', description: 'Return the most recent TYPO3 log entries. Defaults to a compact summary (distinct messages with counts); pass mode=full for individual entries with truncated traces.')]
    public function tail(int $limit = 50, string $mode = 'summary', ?string $since = null): string
    {
        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:logs:search', [], $this->options([
            'limit' => $limit,
            'format' => $mode,
            'since' => $since,
        ])));
    }

    #[McpTool(name: 'typo3-logs-by-level', title: 'TYPO3 Logs by Level', description: 'Return TYPO3 log entries at or above a minimum severity (e.g. error), optionally filtered by request-id. Defaults to a compact summary (distinct messages with counts and lastSeen, no stack traces); pass mode=full for individual entries with truncated traces. Use since (e.g. 1h, 2d) to scope to recent entries.')]
    public function byLevel(string $level, ?string $requestId = null, int $limit = 50, string $mode = 'summary', ?string $since = null): string
    {
        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:logs:search', [], $this->options([
            'level' => $level,
            'request-id' => $requestId,
            'limit' => $limit,
            'format' => $mode,
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
