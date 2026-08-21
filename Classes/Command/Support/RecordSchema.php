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

use InvalidArgumentException;
use KonradMichalik\Typo3AiMate\Support\Cast;

use function array_slice;
use function in_array;
use function is_string;
use function sprintf;

/**
 * RecordSchema.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RecordSchema
{
    /**
     * Substrings (matched case-insensitively against the underscore-stripped
     * column name) that mark a value as secret independent of TCA — a safety net
     * for credential columns that do not carry a `password` TCA type (e.g.
     * api_key, access_token, client_secret, private_key).
     */
    private const SECRET_NAME_PATTERNS = ['password', 'passwd', 'secret', 'token', 'apikey', 'credential', 'privatekey'];

    /**
     * User tables whose personal-data columns are masked by default, so GDPR-
     * relevant fields never reach the AI client.
     */
    private const PII_TABLES = ['fe_users', 'be_users'];

    /**
     * Personal-data columns of the user tables (matched case-insensitively).
     * Deliberately excludes `username` so records stay identifiable for debugging.
     */
    private const PII_COLUMNS = ['email', 'name', 'first_name', 'middle_name', 'last_name', 'realname', 'address', 'city', 'zip', 'country', 'telephone', 'fax', 'title', 'company', 'www', 'image'];

    /**
     * Tables never exposed regardless of column selection: raw session rows hold
     * live session identifiers (be_sessions.ses_id is a valid backend session),
     * so returning them would enable session hijacking if the value reaches an
     * AI conversation log. Matched case-insensitively.
     */
    private const BLOCKED_TABLES = ['be_sessions', 'fe_sessions'];
    private const BLOCKED_TABLE_SUFFIX = '_sessions';

    /**
     * Bookkeeping columns that carry no answer to any question a caller asks a
     * record for: `l18n_diffsource` ships the whole translated row again as an
     * escaped JSON blob, and the workspace columns describe versioning state.
     * Left out of a full selection unless asked for by name.
     */
    private const BOOKKEEPING_COLUMNS = ['l18n_diffsource', 'l10n_diffsource', 'l10n_state'];
    private const BOOKKEEPING_PREFIX = 't3ver_';

    /**
     * Whether a table must not be queried at all (session storage).
     */
    public static function isBlockedTable(string $table): bool
    {
        $table = strtolower(trim($table));

        return in_array($table, self::BLOCKED_TABLES, true) || str_ends_with($table, self::BLOCKED_TABLE_SUFFIX);
    }

    /**
     * Personal-data columns to redact for a given table (empty for non-user
     * tables).
     *
     * @param list<string> $columns
     *
     * @return list<string>
     */
    public static function piiColumns(string $table, array $columns): array
    {
        if (!in_array(strtolower($table), self::PII_TABLES, true)) {
            return [];
        }

        $pii = [];
        foreach ($columns as $column) {
            if (in_array(strtolower($column), self::PII_COLUMNS, true)) {
                $pii[] = $column;
            }
        }

        return $pii;
    }

    /**
     * Columns whose value must be redacted: any column whose name matches a
     * secret pattern plus any column declared as a `password` TCA type.
     *
     * @param array<mixed> $tcaColumns the TCA `columns` section of the table
     * @param list<string> $columns
     *
     * @return list<string>
     */
    public static function sensitiveColumns(array $tcaColumns, array $columns): array
    {
        $sensitive = [];
        foreach ($columns as $column) {
            $config = Cast::array(Cast::array($tcaColumns[$column] ?? null)['config'] ?? null);
            if (self::isSecretName($column) || 'password' === Cast::string($config['type'] ?? '')) {
                $sensitive[] = $column;
            }
        }

        return $sensitive;
    }

    /**
     * @param list<string> $columns
     *
     * @return list<string>
     */
    public static function withoutBookkeeping(array $columns): array
    {
        return array_values(array_filter($columns, static fn (string $column): bool => !in_array(strtolower($column), self::BOOKKEEPING_COLUMNS, true)
            && !str_starts_with(strtolower($column), self::BOOKKEEPING_PREFIX)));
    }

    /**
     * @param array<mixed>          $ctrl
     * @param list<string>          $columns
     * @param array<string, string> $enableColumns
     *
     * @return list<string>
     */
    public static function compactFields(array $ctrl, array $columns, array $enableColumns, ?string $deleteField): array
    {
        $candidates = ['uid', 'pid'];
        foreach (['label', 'label_alt', 'type'] as $key) {
            $candidates[] = self::firstPlainColumn(Cast::string($ctrl[$key] ?? ''));
        }
        foreach ($enableColumns as $column) {
            $candidates[] = $column;
        }
        if (null !== $deleteField) {
            $candidates[] = $deleteField;
        }
        foreach (['tstamp', 'crdate'] as $key) {
            $candidates[] = Cast::string($ctrl[$key] ?? '');
        }

        $selected = self::keepExistingColumns($candidates, $columns);

        // Non-TCA table without uid/pid: fall back to the first columns.
        return [] === $selected ? array_slice($columns, 0, 8) : $selected;
    }

    /**
     * @param array<mixed> $ctrl
     * @param list<string> $columns
     *
     * @return array{0: string|null, 1: string}
     */
    public static function orderBy(mixed $orderBy, array $columns, array $ctrl): array
    {
        if (is_string($orderBy) && '' !== trim($orderBy)) {
            $parts = explode(':', trim($orderBy), 2);
            $field = trim($parts[0]);
            $direction = isset($parts[1]) && 'desc' === strtolower(trim($parts[1])) ? 'DESC' : 'ASC';
            if (!in_array($field, $columns, true)) {
                throw new InvalidArgumentException(sprintf('Unknown field "%s" in order-by.', $field), 5379417855);
            }

            return [$field, $direction];
        }

        $sortby = self::firstPlainColumn(Cast::string($ctrl['sortby'] ?? ''));
        if ('' !== $sortby && in_array($sortby, $columns, true)) {
            return [$sortby, 'ASC'];
        }

        return in_array('uid', $columns, true) ? ['uid', 'ASC'] : [null, 'ASC'];
    }

    /**
     * @param array<mixed> $ctrl
     * @param list<string> $columns
     *
     * @return array<string, string>
     */
    public static function enableColumns(array $ctrl, array $columns): array
    {
        $enable = Cast::array($ctrl['enablecolumns'] ?? null);
        $resolved = [];
        foreach (['disabled', 'starttime', 'endtime', 'fe_group'] as $key) {
            $column = Cast::string($enable[$key] ?? '');
            if ('' !== $column && in_array($column, $columns, true)) {
                $resolved[$key] = $column;
            }
        }

        return $resolved;
    }

    /**
     * @param array<mixed> $ctrl
     * @param list<string> $columns
     */
    public static function deleteField(array $ctrl, array $columns): ?string
    {
        $delete = Cast::string($ctrl['delete'] ?? '');

        return '' !== $delete && in_array($delete, $columns, true) ? $delete : null;
    }

    private static function isSecretName(string $column): bool
    {
        $normalized = str_replace('_', '', strtolower($column));
        foreach (self::SECRET_NAME_PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $candidates
     * @param list<string> $columns
     *
     * @return list<string>
     */
    private static function keepExistingColumns(array $candidates, array $columns): array
    {
        $selected = [];
        foreach ($candidates as $candidate) {
            if ('' !== $candidate && in_array($candidate, $columns, true) && !in_array($candidate, $selected, true)) {
                $selected[] = $candidate;
            }
        }

        return $selected;
    }

    /**
     * A TCA type/label control can carry suffixes (e.g. "uid_local:sys_file")
     * or a comma list; keep only the leading plain column name.
     */
    private static function firstPlainColumn(string $value): string
    {
        $value = trim($value);
        if ('' === $value) {
            return '';
        }
        $parts = preg_split('/[:;,]/', $value);

        return false === $parts ? '' : trim($parts[0]);
    }
}
