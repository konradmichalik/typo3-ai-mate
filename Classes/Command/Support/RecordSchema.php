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
     * @param list<string> $columns
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function parseWhere(mixed $where, array $columns): array
    {
        if (!is_string($where) || '' === trim($where)) {
            return [];
        }

        $constraints = [];
        foreach (explode(',', $where) as $pair) {
            $pair = trim($pair);
            if ('' === $pair) {
                continue;
            }
            if (!str_contains($pair, '=')) {
                throw new InvalidArgumentException(sprintf('Invalid where constraint "%s" (expected field=value).', $pair));
            }
            [$field, $value] = explode('=', $pair, 2);
            $field = trim($field);
            if (!in_array($field, $columns, true)) {
                throw new InvalidArgumentException(sprintf('Unknown field "%s" in where.', $field));
            }
            $constraints[] = [$field, trim($value)];
        }

        return $constraints;
    }

    /**
     * @param list<string> $columns
     *
     * @return list<string>|null null when no explicit selection was given
     */
    public static function parseFields(mixed $fields, array $columns): ?array
    {
        if (!is_string($fields) || '' === trim($fields)) {
            return null;
        }

        $selected = [];
        foreach (explode(',', $fields) as $field) {
            $field = trim($field);
            if ('' !== $field && in_array($field, $columns, true) && !in_array($field, $selected, true)) {
                $selected[] = $field;
            }
        }

        if ([] === $selected) {
            throw new InvalidArgumentException('None of the requested fields exist on this table.');
        }

        return $selected;
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
            if (in_array($field, $columns, true)) {
                return [$field, $direction];
            }
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
