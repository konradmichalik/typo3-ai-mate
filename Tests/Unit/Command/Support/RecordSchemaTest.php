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
        self::assertSame(['uid', 'ASC'], RecordSchema::orderBy(null, ['uid', 'value'], []));
        self::assertSame([null, 'ASC'], RecordSchema::orderBy(null, ['value'], []));
    }

    #[Test]
    public function orderByRejectsAnUnknownExplicitField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RecordSchema::orderBy('ghost', self::COLUMNS, self::CTRL);
    }

    #[Test]
    public function sensitiveColumnsMatchesSecretNamesAndPasswordTcaType(): void
    {
        $tcaColumns = [
            'secret_token' => ['config' => ['type' => 'password']],
            'username' => ['config' => ['type' => 'input']],
        ];
        $columns = ['uid', 'username', 'password', 'secret_token'];

        self::assertSame(['password', 'secret_token'], RecordSchema::sensitiveColumns($tcaColumns, $columns));
        self::assertSame([], RecordSchema::sensitiveColumns([], ['uid', 'username']));
    }
}
