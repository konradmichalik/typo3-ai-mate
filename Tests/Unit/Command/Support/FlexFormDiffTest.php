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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command\Support;

use KonradMichalik\Typo3AiMate\Command\Support\FlexFormDiff;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FlexFormDiffTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class FlexFormDiffTest extends TestCase
{
    #[Test]
    public function dataStructureFieldsReadsTypeAndDefaultPerSheet(): void
    {
        $parsed = [
            'sheets' => [
                'sDEF' => ['ROOT' => ['type' => 'array', 'el' => [
                    'settings.maxItems' => ['label' => 'Max', 'config' => ['type' => 'number', 'default' => 6]],
                    'settings.plain' => ['config' => ['type' => 'input']],
                ]]],
                'extra' => ['ROOT' => ['el' => ['settings.other' => ['config' => ['type' => 'check']]]]],
            ],
        ];

        self::assertSame([
            'sDEF/settings.maxItems' => ['type' => 'number', 'default' => 6],
            'sDEF/settings.plain' => ['type' => 'input'],
            'extra/settings.other' => ['type' => 'check'],
        ], FlexFormDiff::dataStructureFields($parsed));
    }

    #[Test]
    public function dataStructureFieldsIsEmptyForAStructureWithoutSheets(): void
    {
        self::assertSame([], FlexFormDiff::dataStructureFields([]));
    }

    #[Test]
    public function storedValuesFlattensSheetLanguageAndValueDimensions(): void
    {
        $data = ['data' => ['sDEF' => ['lDEF' => [
            'settings.limit' => ['vDEF' => '9'],
            'settings.plain' => ['vDEF' => 'text'],
        ]]]];

        self::assertSame(
            ['sDEF/settings.limit' => '9', 'sDEF/settings.plain' => 'text'],
            FlexFormDiff::storedValues($data),
        );
    }

    #[Test]
    public function storedValuesPrefersTheDefaultDimensionButStillReportsOthers(): void
    {
        $data = ['data' => ['sDEF' => [
            'lDEF' => ['settings.limit' => ['vDEF' => 'default']],
            'lDE' => ['settings.limit' => ['vDEF' => 'german'], 'settings.only_translated' => ['vDEF' => 'x']],
        ]]];

        $values = FlexFormDiff::storedValues($data);

        self::assertSame('default', $values['sDEF/settings.limit']);
        // A field stored only in a non-default dimension is still stored.
        self::assertSame('x', $values['sDEF/settings.only_translated']);
    }

    #[Test]
    public function compareClassifiesOrphanedAndMissingFields(): void
    {
        $fields = [
            'sDEF/settings.maxItems' => ['type' => 'number', 'default' => 6],
            'sDEF/settings.plain' => ['type' => 'input'],
        ];
        $stored = [
            'sDEF/settings.limit' => '9',
            'sDEF/settings.plain' => 'text',
        ];

        self::assertSame([
            'matched' => ['sDEF/settings.plain' => 'text'],
            // The renamed field: still stored, no longer declared, silently ignored.
            'orphaned' => ['sDEF/settings.limit' => '9'],
            'missing' => ['sDEF/settings.maxItems' => ['type' => 'number', 'default' => 6]],
        ], FlexFormDiff::compare($fields, $stored));
    }

    #[Test]
    public function compareReportsNothingOrphanedWhenTheStructureMatches(): void
    {
        $diff = FlexFormDiff::compare(
            ['sDEF/settings.limit' => ['type' => 'number']],
            ['sDEF/settings.limit' => '9'],
        );

        self::assertSame([], $diff['orphaned']);
        self::assertSame([], $diff['missing']);
        self::assertSame(['sDEF/settings.limit' => '9'], $diff['matched']);
    }
}
