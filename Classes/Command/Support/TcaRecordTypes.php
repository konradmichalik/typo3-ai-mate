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

use function count;

/**
 * TcaRecordTypes.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class TcaRecordTypes
{
    /**
     * Collapse the fields that every record type carries into one shared list.
     * tt_content repeats 15 near-universal field names (CType, colPos, header,
     * hidden, …) once per record type, which is a third of that block for a
     * question that is usually about one type. Lossless: a type's full field set
     * is `shared` plus its own entry.
     *
     * @param array<string, list<string>> $recordTypes
     *
     * @return array{shared: list<string>, types: array<string, list<string>>, _hint?: string}
     */
    public static function collapse(array $recordTypes): array
    {
        // Nothing is "shared" below two types; the collapse would only move the
        // fields into another key.
        $shared = count($recordTypes) > 1
            ? array_values(array_intersect(...array_values($recordTypes)))
            : [];

        $result = [
            'shared' => $shared,
            'types' => array_map(
                static fn (array $fields): array => array_values(array_diff($fields, $shared)),
                $recordTypes,
            ),
        ];
        if ([] !== $shared) {
            $result['_hint'] = 'types lists only the fields a type adds beyond shared; a type\'s full field set is shared plus its own entry.';
        }

        return $result;
    }

    /**
     * Reduce every type's field list to the requested fields. "Which record
     * types show these fields" is the question a field filter asks; the other
     * types' complete field lists are not part of it, and a type that shows
     * none of them is dropped rather than reported as an empty list — on
     * tt_content that is 25 of 27 types for a single requested field, and an
     * empty list would also collapse `shared` to nothing.
     *
     * @param array<string, list<string>> $recordTypes
     * @param list<string>                $fields
     *
     * @return array<string, list<string>>
     */
    public static function limitToFields(array $recordTypes, array $fields): array
    {
        return array_filter(
            array_map(
                static fn (array $typeFields): array => array_values(array_intersect($typeFields, $fields)),
                $recordTypes,
            ),
            static fn (array $typeFields): bool => [] !== $typeFields,
        );
    }
}
