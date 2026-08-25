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

use KonradMichalik\Typo3AiMate\Mate\{ToolResult, Typo3CliRunner};
use KonradMichalik\Typo3AiMate\Mcp\Enum\OutputMode;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

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
     * @param string|null $fields              Comma-separated explicit column selection; omit for a compact default set. A column named here is always reported, even when its value is empty.
     * @param int         $limit               maximum rows to return (capped at 100)
     * @param string|null $orderBy             order by a column, optionally with direction: field or field:desc
     * @param OutputMode  $mode                summary (default, compact core fields with long text truncated) | full (every column but the bookkeeping ones, untruncated)
     * @param bool        $respectEnableFields apply Deleted/Hidden/StartEnd restrictions (frontend view); default false shows every row with _flags
     */
    #[McpTool(
        name: 'typo3-records',
        title: 'TYPO3 Records',
        description: 'Read-only record query for a TYPO3 table. Returns rows as compact JSON (uid, pid, label/type, enable columns, timestamps; long text truncated), each with a _flags list (hidden/deleted/timed/fe_group) explaining visibility. No restrictions are applied by default, so hidden/deleted rows are visible — use this instead of raw SQL to answer "why is this record not showing?". Columns whose value is empty, zero or null are left out of a row (uid and pid always stay), and mode=full omits the versioning and l18n_diffsource bookkeeping columns; name a column in fields to read it regardless.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'Echoed back table name.'],
                'count' => ['type' => 'integer', 'description' => 'Number of rows returned (after limit is applied). 0 is a real answer: no row matched — see _hint.'],
                'limited' => ['type' => 'boolean', 'description' => 'true when more rows matched than limit allowed and the result was truncated.'],
                'restrictionsApplied' => ['type' => 'boolean', 'description' => 'Echoes back respectEnableFields: whether Deleted/Hidden/StartEnd restrictions were applied to the query.'],
                '_hint' => ['type' => 'string', 'description' => 'Present when rows is empty (explains no row matched, and whether restrictions may be hiding one) or when columns were auto-trimmed to the compact default set.'],
                'rows' => [
                    'type' => 'array',
                    'description' => 'One entry per matched row. Columns with an empty/zero/null value are omitted unless fields was passed explicitly (uid/pid always stay). Each row carries _flags: {hidden, deleted, timed, fe_group} explaining its visibility.',
                    'items' => ['type' => 'object'],
                ],
                'error' => ['type' => 'string', 'description' => 'Present, alongside validColumns, when where/fields/orderBy named a column the table does not have — a request-validation answer, distinct from unsupported (which means the tool could not answer at all).'],
                'validColumns' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Present only alongside error: the column names the table actually has.'],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all: an unknown or blocked table, or the console was unreachable.'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
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
    ): CallToolResult {
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

        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:records:query', [$table], $options));
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
