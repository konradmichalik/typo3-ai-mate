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

/**
 * FlexFormDiff.
 *
 * Reconciles the currently valid data structure of a FlexForm field against the
 * values actually stored on a record. When a data structure renames a field, the
 * old key stays in the record's XML and silently stops applying — nothing in the
 * backend says so.
 *
 * Scope: fields are identified as `<sheet>/<fieldName>`. FlexForm language and
 * value dimensions beyond lDEF/vDEF are not distinguished, because the question
 * here is which fields are stored, not which translation of them. Section and
 * container contents are reported as the single field that holds them rather
 * than descended into.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class FlexFormDiff
{
    /**
     * Fields the current data structure declares, keyed `<sheet>/<fieldName>`.
     *
     * @param array<mixed> $parsed as returned by FlexFormTools::parseDataStructureByIdentifier()
     *
     * @return array<string, array<string, mixed>>
     */
    public static function dataStructureFields(array $parsed): array
    {
        $fields = [];
        foreach (Cast::array($parsed['sheets'] ?? null) as $sheet => $definition) {
            $elements = Cast::array(Cast::array(Cast::array($definition)['ROOT'] ?? null)['el'] ?? null);
            foreach ($elements as $fieldName => $element) {
                $config = Cast::array(Cast::array($element)['config'] ?? null);
                $fields[Cast::string($sheet).'/'.Cast::string($fieldName)] = array_filter([
                    'type' => Cast::string($config['type'] ?? ''),
                    'default' => $config['default'] ?? null,
                ], static fn (mixed $value): bool => null !== $value && '' !== $value);
            }
        }

        return $fields;
    }

    /**
     * Values stored on the record, keyed `<sheet>/<fieldName>`.
     *
     * @param array<mixed> $data as returned by GeneralUtility::xml2array() on the stored value
     *
     * @return array<string, mixed>
     */
    public static function storedValues(array $data): array
    {
        $values = [];
        foreach (Cast::array($data['data'] ?? null) as $sheet => $languages) {
            foreach (Cast::array($languages) as $language => $fields) {
                $values = self::mergeLanguage($values, Cast::string($sheet), 'lDEF' === $language, Cast::array($fields));
            }
        }

        return $values;
    }

    /**
     * The answer: which stored values no longer have a field, and which fields
     * have no stored value.
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array<string, mixed>                $stored
     *
     * @return array{matched: array<string, mixed>, orphaned: array<string, mixed>, missing: array<string, array<string, mixed>>}
     */
    public static function compare(array $fields, array $stored): array
    {
        $matched = [];
        $orphaned = [];
        foreach ($stored as $key => $value) {
            if (array_key_exists($key, $fields)) {
                $matched[$key] = $value;
            } else {
                $orphaned[$key] = $value;
            }
        }

        $missing = [];
        foreach ($fields as $key => $definition) {
            if (!array_key_exists($key, $stored)) {
                $missing[$key] = $definition;
            }
        }

        return ['matched' => $matched, 'orphaned' => $orphaned, 'missing' => $missing];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<mixed>         $fields
     *
     * @return array<string, mixed>
     */
    private static function mergeLanguage(array $values, string $sheet, bool $isDefaultLanguage, array $fields): array
    {
        foreach ($fields as $fieldName => $valueKeys) {
            foreach (Cast::array($valueKeys) as $valueKey => $value) {
                $key = $sheet.'/'.Cast::string($fieldName);
                // The default dimension wins; a non-default one still proves the
                // field is stored, which is what a diff needs.
                if (!array_key_exists($key, $values) || ($isDefaultLanguage && 'vDEF' === $valueKey)) {
                    $values[$key] = $value;
                }
            }
        }

        return $values;
    }
}
