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

use function in_array;
use function is_string;
use function sprintf;

/**
 * RecordQueryInput.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RecordQueryInput
{
    /**
     * Resolve an optional numeric option: null when absent, int when numeric,
     * and a hard error when present but non-numeric (rather than silently
     * dropping the filter).
     */
    public static function intOption(mixed $value, string $name): ?int
    {
        if (null === $value) {
            return null;
        }
        if (!is_numeric($value)) {
            throw new InvalidArgumentException(sprintf('Option "%s" must be numeric, got "%s".', $name, Cast::string($value)), 4232683209);
        }

        return (int) $value;
    }

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
                throw new InvalidArgumentException(sprintf('Invalid where constraint "%s" (expected field=value).', $pair), 2337432224);
            }
            [$field, $value] = explode('=', $pair, 2);
            $field = trim($field);
            if (!in_array($field, $columns, true)) {
                throw new InvalidArgumentException(sprintf('Unknown field "%s" in where.', $field), 4626974546);
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
            throw new InvalidArgumentException('None of the requested fields exist on this table.', 1546852278);
        }

        return $selected;
    }
}
