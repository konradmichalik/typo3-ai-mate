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
use KonradMichalik\Typo3AiMate\Mcp\Enum\SysLogType;
use Symfony\AI\Mate\Attribute\MateTool;

/**
 * SysLogTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class SysLogTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param bool            $error     only entries flagged as an error (user error, system error, security notice, warning); default false returns every entry
     * @param string|null     $table     restrict to one table, e.g. tt_content; omit for all tables
     * @param int|null        $recordUid restrict to one record uid, usually combined with table; omit for all records
     * @param SysLogType|null $type      db | file | cache | extension | error | site | setting | login; omit for all types
     * @param string|null     $since     Relative time window, e.g. 1h, 2d; omit for all entries regardless of age.
     * @param int             $limit     maximum number of distinct entries to return (default 50, capped at 200)
     */
    #[MateTool(
        name: 'typo3-syslog',
        title: 'TYPO3 System Log',
        description: 'Backend and DataHandler events from the sys_log table, which the typo3-logs-* tools do not read: permission errors ("Attempt to modify record ... without permission"), failed file operations, workspace errors. Use it when a save in the backend did not work. Returns entries newest first, deduplicated with occurrence counts and lastSeen, each with type, action, error level, table, record uid, page uid and the details text with its placeholders filled. User ids, IP addresses and workspace ids are never returned, and login entries and be_users/fe_users records keep their unfilled template so no user name leaks.',
    )]
    public function search(
        bool $error = false,
        ?string $table = null,
        ?int $recordUid = null,
        ?SysLogType $type = null,
        ?string $since = null,
        int $limit = 50,
    ): string {
        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:syslog:search', [], $this->options([
            'error' => $error ?: null,
            'table' => $table,
            'record-uid' => $recordUid,
            'type' => $type?->value,
            'since' => $since,
            'limit' => $limit,
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
