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

use KonradMichalik\Typo3AiMate\Command\Support\RecordTrimmer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RecordTrimmerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RecordTrimmerTest extends TestCase
{
    #[Test]
    public function valueIsCappedOnlyWhenItExceedsTheLimit(): void
    {
        self::assertSame('short', RecordTrimmer::truncate('short', 10));
        self::assertSame(str_repeat('x', 10).'…(+40)', RecordTrimmer::truncate(str_repeat('x', 50), 10));
    }

    #[Test]
    public function truncateRowOnlyTouchesStringsAndRespectsZeroLimit(): void
    {
        $row = ['uid' => 5, 'header' => str_repeat('h', 20)];

        $trimmed = RecordTrimmer::truncateRow($row, 5);
        self::assertSame(5, $trimmed['uid']);
        self::assertSame('hhhhh…(+15)', $trimmed['header']);

        self::assertSame($row, RecordTrimmer::truncateRow($row, 0));
    }

    #[Test]
    public function flagsHiddenAndDeletedRows(): void
    {
        $enable = ['disabled' => 'hidden'];

        self::assertSame(['hidden'], RecordTrimmer::flags(['hidden' => 1], $enable, 'deleted', 1000));
        self::assertSame(['deleted'], RecordTrimmer::flags(['hidden' => 0, 'deleted' => 1], $enable, 'deleted', 1000));
        self::assertSame([], RecordTrimmer::flags(['hidden' => 0, 'deleted' => 0], $enable, 'deleted', 1000));
    }

    #[Test]
    public function flagsTimedRowsFromStartAndEndTime(): void
    {
        $enable = ['starttime' => 'starttime', 'endtime' => 'endtime'];

        // starttime in the future
        self::assertSame(['timed'], RecordTrimmer::flags(['starttime' => 2000, 'endtime' => 0], $enable, null, 1000));
        // endtime in the past
        self::assertSame(['timed'], RecordTrimmer::flags(['starttime' => 0, 'endtime' => 500], $enable, null, 1000));
        // active window
        self::assertSame([], RecordTrimmer::flags(['starttime' => 500, 'endtime' => 2000], $enable, null, 1000));
    }

    #[Test]
    public function redactReplacesSensitiveColumnsThatArePresent(): void
    {
        $row = ['uid' => 1, 'username' => 'admin', 'password' => 'hash'];

        $redacted = RecordTrimmer::redact($row, ['password', 'absent']);
        self::assertSame('***', $redacted['password']);
        self::assertSame('admin', $redacted['username']);
        self::assertArrayNotHasKey('absent', $redacted);
    }

    #[Test]
    public function flagsFeGroupRestriction(): void
    {
        $enable = ['fe_group' => 'fe_group'];

        self::assertSame(['fe_group'], RecordTrimmer::flags(['fe_group' => '1,2'], $enable, null, 1000));
        self::assertSame([], RecordTrimmer::flags(['fe_group' => '0'], $enable, null, 1000));
        self::assertSame([], RecordTrimmer::flags(['fe_group' => ''], $enable, null, 1000));
    }
}
