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

use KonradMichalik\Typo3AiMate\Support\Cast;

use function array_key_exists;
use function is_string;

/**
 * RecordTrimmer.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RecordTrimmer
{
    /**
     * Cap a single string value, appending how many characters were dropped so
     * the assistant knows the value is longer than shown.
     */
    public static function truncate(string $value, int $limit): string
    {
        $length = mb_strlen($value);

        return $length > $limit ? mb_substr($value, 0, $limit).'…(+'.($length - $limit).')' : $value;
    }

    /**
     * Truncate every string column of a row. A limit of 0 leaves values untouched.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function truncateRow(array $row, int $limit): array
    {
        if (0 === $limit) {
            return $row;
        }

        $out = [];
        foreach ($row as $key => $value) {
            $out[$key] = is_string($value) ? self::truncate($value, $limit) : $value;
        }

        return $out;
    }

    /**
     * Replace the value of every sensitive column with a redaction marker so
     * credentials and PII never reach the AI response or logs.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $sensitiveColumns
     *
     * @return array<string, mixed>
     */
    public static function redact(array $row, array $sensitiveColumns): array
    {
        foreach ($sensitiveColumns as $column) {
            if (array_key_exists($column, $row)) {
                $row[$column] = '***';
            }
        }

        return $row;
    }

    /**
     * Derive visibility flags from the row's enable/delete columns.
     *
     * @param array<string, mixed>                                                              $row
     * @param array{disabled?: string, starttime?: string, endtime?: string, fe_group?: string} $enableColumns
     *
     * @return list<string>
     */
    public static function flags(array $row, array $enableColumns, ?string $deleteField, int $now): array
    {
        $flags = [];
        if (self::flagSet($row, $enableColumns['disabled'] ?? null)) {
            $flags[] = 'hidden';
        }
        if (self::flagSet($row, $deleteField)) {
            $flags[] = 'deleted';
        }
        if (self::isTimed($row, $enableColumns, $now)) {
            $flags[] = 'timed';
        }
        if (self::isFeGroupRestricted($row, $enableColumns['fe_group'] ?? null)) {
            $flags[] = 'fe_group';
        }

        return $flags;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function flagSet(array $row, ?string $column): bool
    {
        return null !== $column && 1 === Cast::int($row[$column] ?? 0);
    }

    /**
     * @param array<string, mixed>  $row
     * @param array<string, string> $enableColumns
     */
    private static function isTimed(array $row, array $enableColumns, int $now): bool
    {
        $start = isset($enableColumns['starttime']) ? Cast::int($row[$enableColumns['starttime']] ?? 0) : 0;
        $end = isset($enableColumns['endtime']) ? Cast::int($row[$enableColumns['endtime']] ?? 0) : 0;

        return ($start > 0 && $start > $now) || ($end > 0 && $end < $now);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function isFeGroupRestricted(array $row, ?string $column): bool
    {
        if (null === $column) {
            return false;
        }
        $value = Cast::string($row[$column] ?? '');

        return '' !== $value && '0' !== $value;
    }
}
