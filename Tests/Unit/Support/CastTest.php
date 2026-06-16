<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <km@move-elevator.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Support;

use KonradMichalik\Typo3AiMate\Support\Cast;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * CastTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final class CastTest extends TestCase
{
    #[Test]
    public function intNarrowsNumericValuesAndDefaultsToZero(): void
    {
        self::assertSame(42, Cast::int(42));
        self::assertSame(42, Cast::int('42'));
        self::assertSame(7, Cast::int(7.9));
        self::assertSame(0, Cast::int('not a number'));
        self::assertSame(0, Cast::int(null));
        self::assertSame(0, Cast::int(['x']));
    }

    #[Test]
    public function stringNarrowsScalarsAndDefaultsToEmpty(): void
    {
        self::assertSame('hello', Cast::string('hello'));
        self::assertSame('42', Cast::string(42));
        self::assertSame('1', Cast::string(true));
        self::assertSame('', Cast::string(null));
        self::assertSame('', Cast::string(['x']));
        self::assertSame('', Cast::string(new stdClass()));
    }

    #[Test]
    public function arrayPassesArraysThroughAndDefaultsToEmpty(): void
    {
        self::assertSame(['a' => 1], Cast::array(['a' => 1]));
        self::assertSame([], Cast::array(null));
        self::assertSame([], Cast::array('string'));
        self::assertSame([], Cast::array(42));
    }
}
