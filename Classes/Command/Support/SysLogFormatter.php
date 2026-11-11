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

namespace KonradMichalik\Typo3AiMate\Command\Support;

use KonradMichalik\Typo3AiMate\Mcp\Enum\SysLogType;
use KonradMichalik\Typo3AiMate\Support\Cast;
use TYPO3\CMS\Core\Log\LogDataTrait;

use function in_array;

/**
 * SysLogFormatter.
 *
 * Turns `sys_log` rows into deduplicated entries. Only the columns named here
 * reach the output: `userid`, `IP`, `workspace` and the PSR-3 `data` are never
 * read.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class SysLogFormatter
{
    use LogDataTrait;

    private const DETAILS_LIMIT = 2000;

    /**
     * Values of `TYPO3\CMS\Core\SysLog\Type`.
     */
    private const TYPES = [
        1 => SysLogType::Db,
        2 => SysLogType::File,
        3 => SysLogType::Cache,
        4 => SysLogType::Extension,
        5 => SysLogType::Error,
        6 => SysLogType::Site,
        254 => SysLogType::Setting,
        255 => SysLogType::Login,
    ];

    /**
     * Values of `TYPO3\CMS\Core\SysLog\Action\Database` and `...\Action\File`,
     * the two types whose failures this tool exists for.
     */
    private const ACTIONS = [
        1 => [1 => 'insert', 2 => 'update', 3 => 'delete', 4 => 'move', 5 => 'check', 6 => 'localize', 7 => 'versionize', 8 => 'publish', 9 => 'discard'],
        2 => [1 => 'upload', 2 => 'copy', 3 => 'move', 4 => 'delete', 5 => 'rename', 6 => 'newFolder', 7 => 'unzip', 8 => 'newFile', 9 => 'edit'],
    ];

    /**
     * Values of `TYPO3\CMS\Core\SysLog\Error`.
     */
    private const ERRORS = [0 => 'message', 1 => 'userError', 2 => 'systemError', 3 => 'securityNotice', 4 => 'warning'];

    /**
     * Rows whose log data names a person: login events carry the user name, and
     * a be_users/fe_users record's label is its user name.
     */
    private const IDENTITY_TABLES = ['be_users', 'fe_users'];

    public function typeId(string $name): ?int
    {
        $type = SysLogType::tryFrom(strtolower(trim($name)));
        $id = null === $type ? false : array_search($type, self::TYPES, true);

        return false === $id ? null : $id;
    }

    /**
     * The details text with its placeholders filled the way the backend log
     * module does it, then redacted and capped. Identity rows keep the unfilled
     * template. Rows of the PSR-3 DatabaseWriter carry no details but a message,
     * which that writer stores uninterpolated, so it is shown as stored.
     *
     * @param array<string, mixed> $row
     */
    public function details(array $row): string
    {
        $template = Cast::string($row['details'] ?? '');
        if ('' === $template) {
            return LogTrimmer::message(Cast::string($row['message'] ?? ''), self::DETAILS_LIMIT);
        }
        $details = $this->isIdentityRow($row) ? $template : $this->formatLogDetails($template, $row['log_data'] ?? '');

        return LogTrimmer::message($details, self::DETAILS_LIMIT);
    }

    /**
     * Group identical events, count them and keep the newest timestamp. The
     * result is ordered newest first.
     *
     * @param iterable<array<string, mixed>> $rows
     *
     * @return list<array{lastSeen: string, count: int, type: string|int, action: string|int, error: string|int, level: string, component: string, table: string, recordUid: int|null, pageUid: int, exampleRequestId: string, details: string}>
     */
    public function aggregate(iterable $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $entry = $this->entry($row);
            $key = implode("\0", [$entry['type'], $entry['action'], $entry['error'], $entry['level'], $entry['component'], $entry['table'], $entry['recordUid'], $entry['details']]);
            $timestamp = Cast::int($row['tstamp'] ?? 0);
            $grouped[$key] ??= ['timestamp' => $timestamp, 'count' => 0, 'entry' => $entry];
            ++$grouped[$key]['count'];
            if ($timestamp > $grouped[$key]['timestamp']) {
                $grouped[$key] = ['timestamp' => $timestamp, 'count' => $grouped[$key]['count'], 'entry' => $entry];
            }
        }
        usort($grouped, static fn (array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);

        return array_map(
            static fn (array $group): array => ['lastSeen' => date(\DATE_ATOM, $group['timestamp']), 'count' => $group['count'], ...$group['entry']],
            $grouped,
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{type: string|int, action: string|int, error: string|int, level: string, component: string, table: string, recordUid: int|null, pageUid: int, exampleRequestId: string, details: string}
     */
    private function entry(array $row): array
    {
        $type = Cast::int($row['type'] ?? 0);
        $action = Cast::int($row['action'] ?? 0);
        $error = Cast::int($row['error'] ?? 0);

        return [
            'type' => isset(self::TYPES[$type]) ? self::TYPES[$type]->value : $type,
            'action' => self::ACTIONS[$type][$action] ?? $action,
            'error' => self::ERRORS[$error] ?? $error,
            'level' => Cast::string($row['level'] ?? ''),
            'component' => Cast::string($row['component'] ?? ''),
            'table' => Cast::string($row['tablename'] ?? ''),
            'recordUid' => in_array(Cast::string($row['tablename'] ?? ''), self::IDENTITY_TABLES, true) ? null : Cast::int($row['recuid'] ?? 0),
            'pageUid' => Cast::int($row['event_pid'] ?? 0),
            'exampleRequestId' => Cast::string($row['request_id'] ?? ''),
            'details' => $this->details($row),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isIdentityRow(array $row): bool
    {
        return SysLogType::Login === (self::TYPES[Cast::int($row['type'] ?? 0)] ?? null)
            || in_array(Cast::string($row['tablename'] ?? ''), self::IDENTITY_TABLES, true);
    }
}
