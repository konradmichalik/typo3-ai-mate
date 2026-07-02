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

use InvalidArgumentException;
use KonradMichalik\Typo3AiMate\Command\Support\RecordSchema;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RecordSchemaTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RecordSchemaTest extends TestCase
{
    private const COLUMNS = ['uid', 'pid', 'header', 'CType', 'bodytext', 'hidden', 'deleted', 'starttime', 'endtime', 'fe_group', 'tstamp', 'sorting'];

    private const CTRL = [
        'label' => 'header',
        'type' => 'CType',
        'sortby' => 'sorting',
        'tstamp' => 'tstamp',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
            'starttime' => 'starttime',
            'endtime' => 'endtime',
            'fe_group' => 'fe_group',
        ],
    ];

    #[Test]
    public function parseWhereReturnsFieldValuePairs(): void
    {
        self::assertSame([['CType', 'text'], ['hidden', '0']], RecordSchema::parseWhere('CType=text, hidden=0', self::COLUMNS));
        self::assertSame([], RecordSchema::parseWhere(null, self::COLUMNS));
    }

    #[Test]
    public function parseWhereRejectsUnknownFieldsAndMalformedPairs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RecordSchema::parseWhere('nope=1', self::COLUMNS);
    }

    #[Test]
    public function parseFieldsKeepsOnlyExistingColumnsOrThrowsWhenNoneMatch(): void
    {
        self::assertNull(RecordSchema::parseFields(null, self::COLUMNS));
        self::assertSame(['uid', 'header'], RecordSchema::parseFields('uid, header, ghost', self::COLUMNS));

        $this->expectException(InvalidArgumentException::class);
        RecordSchema::parseFields('ghost', self::COLUMNS);
    }

    #[Test]
    public function compactFieldsCollectsLabelTypeEnableAndTimestampColumns(): void
    {
        $enable = RecordSchema::enableColumns(self::CTRL, self::COLUMNS);
        $delete = RecordSchema::deleteField(self::CTRL, self::COLUMNS);

        $fields = RecordSchema::compactFields(self::CTRL, self::COLUMNS, $enable, $delete);

        self::assertSame(['uid', 'pid', 'header', 'CType', 'hidden', 'starttime', 'endtime', 'fe_group', 'deleted', 'tstamp'], $fields);
        self::assertNotContains('bodytext', $fields);
    }

    #[Test]
    public function compactFieldsFallsBackToFirstColumnsForNonTcaTable(): void
    {
        $columns = ['id', 'value', 'created'];

        self::assertSame($columns, RecordSchema::compactFields([], $columns, [], null));
    }

    #[Test]
    public function orderByPrefersExplicitInputThenSortbyThenUid(): void
    {
        self::assertSame(['header', 'DESC'], RecordSchema::orderBy('header:desc', self::COLUMNS, self::CTRL));
        self::assertSame(['sorting', 'ASC'], RecordSchema::orderBy(null, self::COLUMNS, self::CTRL));
        self::assertSame(['uid', 'ASC'], RecordSchema::orderBy('ghost', ['uid', 'value'], []));
        self::assertSame([null, 'ASC'], RecordSchema::orderBy(null, ['value'], []));
    }
}
