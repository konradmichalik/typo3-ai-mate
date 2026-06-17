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

    #[McpTool(name: 'typo3-logs-search', title: 'TYPO3 Log Search', description: 'Full-text search the TYPO3 logs with optional level/component/request-id filters.')]
    public function search(
        ?string $query = null,
        ?string $level = null,
        ?string $component = null,
        ?string $requestId = null,
        int $limit = 50,
    ): string {
        return ResponseEncoder::encode(['entries' => $this->typo3->jsonOrError('typo3-ai-mate:logs:search', [], $this->options([
            'query' => $query,
            'level' => $level,
            'component' => $component,
            'request-id' => $requestId,
            'limit' => $limit,
        ]))]);
    }

    #[McpTool(name: 'typo3-logs-tail', title: 'TYPO3 Log Tail', description: 'Return the most recent TYPO3 log entries.')]
    public function tail(int $limit = 50): string
    {
        return ResponseEncoder::encode(['entries' => $this->typo3->jsonOrError('typo3-ai-mate:logs:search', [], ['limit' => $limit])]);
    }

    #[McpTool(name: 'typo3-logs-by-level', title: 'TYPO3 Logs by Level', description: 'Return TYPO3 log entries at or above a minimum severity (e.g. error), optionally filtered by request-id.')]
    public function byLevel(string $level, ?string $requestId = null, int $limit = 50): string
    {
        return ResponseEncoder::encode(['entries' => $this->typo3->jsonOrError('typo3-ai-mate:logs:search', [], $this->options([
            'level' => $level,
            'request-id' => $requestId,
            'limit' => $limit,
        ]))]);
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
