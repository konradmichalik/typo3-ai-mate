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
use KonradMichalik\Typo3AiMate\Mcp\Enum\OutputMode;
use Mcp\Capability\Attribute\McpTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * RecordsTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RecordsTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string      $table               database table to query, e.g. tt_content or pages
     * @param int|null    $uid                 return a single record by uid
     * @param int|null    $pid                 filter by parent page id
     * @param string|null $where               simple field=value pairs, comma-separated and AND-combined (equality only), e.g. CType=text,colPos=0
     * @param string|null $fields              comma-separated explicit column selection; omit for a compact default set
     * @param int         $limit               maximum rows to return (capped at 100)
     * @param string|null $orderBy             order by a column, optionally with direction: field or field:desc
     * @param OutputMode  $mode                summary (default, compact core fields with long text truncated) | full (all columns, untruncated)
     * @param bool        $respectEnableFields apply Deleted/Hidden/StartEnd restrictions (frontend view); default false shows every row with _flags
     */
    #[McpTool(name: 'typo3-records', title: 'TYPO3 Records', description: 'Read-only record query for a TYPO3 table. Returns rows as compact JSON (uid, pid, label/type, enable columns, timestamps; long text truncated), each with a _flags list (hidden/deleted/timed/fe_group) explaining visibility. By default no restrictions are applied so hidden/deleted rows are visible — use this instead of raw SQL to answer "why is this record not showing?". Pass fields for specific columns, mode=full for all columns, respectEnableFields=true for the frontend view. Equality filters only; narrow via uid/pid.')]
    public function query(
        string $table,
        ?int $uid = null,
        ?int $pid = null,
        ?string $where = null,
        ?string $fields = null,
        int $limit = 25,
        ?string $orderBy = null,
        OutputMode $mode = OutputMode::Summary,
        bool $respectEnableFields = false,
    ): string {
        $options = $this->options([
            'uid' => $uid,
            'pid' => $pid,
            'where' => $where,
            'fields' => $fields,
            'limit' => $limit,
            'order-by' => $orderBy,
            'format' => $mode->value,
            'respect-enable-fields' => $respectEnableFields ?: null,
        ]);

        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:records:query', [$table], $options));
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
