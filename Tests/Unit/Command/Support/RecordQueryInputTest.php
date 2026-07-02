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
use KonradMichalik\Typo3AiMate\Command\Support\RecordQueryInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RecordQueryInputTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RecordQueryInputTest extends TestCase
{
    private const COLUMNS = ['uid', 'pid', 'header', 'CType', 'hidden'];

    #[Test]
    public function intOptionReturnsNullIntOrThrows(): void
    {
        self::assertNull(RecordQueryInput::intOption(null, 'uid'));
        self::assertSame(5, RecordQueryInput::intOption('5', 'uid'));

        $this->expectException(InvalidArgumentException::class);
        RecordQueryInput::intOption('abc', 'uid');
    }

    #[Test]
    public function parseWhereReturnsFieldValuePairs(): void
    {
        self::assertSame([['CType', 'text'], ['hidden', '0']], RecordQueryInput::parseWhere('CType=text, hidden=0', self::COLUMNS));
        self::assertSame([], RecordQueryInput::parseWhere(null, self::COLUMNS));
    }

    #[Test]
    public function parseWhereRejectsUnknownFieldsAndMalformedPairs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RecordQueryInput::parseWhere('nope=1', self::COLUMNS);
    }

    #[Test]
    public function parseFieldsKeepsOnlyExistingColumnsOrThrowsWhenNoneMatch(): void
    {
        self::assertNull(RecordQueryInput::parseFields(null, self::COLUMNS));
        self::assertSame(['uid', 'header'], RecordQueryInput::parseFields('uid, header, ghost', self::COLUMNS));

        $this->expectException(InvalidArgumentException::class);
        RecordQueryInput::parseFields('ghost', self::COLUMNS);
    }
}
