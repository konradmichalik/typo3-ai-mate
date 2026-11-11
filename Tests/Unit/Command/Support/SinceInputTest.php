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

use KonradMichalik\Typo3AiMate\Command\Support\SinceInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SinceInputTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class SinceInputTest extends TestCase
{
    #[Test]
    public function timestampParsesRelativeOffsetsAndDates(): void
    {
        $now = time();
        $thirtyMinutes = SinceInput::timestamp('30m');
        self::assertIsInt($thirtyMinutes);
        self::assertEqualsWithDelta($now - 1800, $thirtyMinutes, 5);

        $twoDays = SinceInput::timestamp('2d');
        self::assertIsInt($twoDays);
        self::assertEqualsWithDelta($now - 172800, $twoDays, 5);

        self::assertSame(strtotime('Mon, 15 Jun 2026 16:16:25 +0200'), SinceInput::timestamp('Mon, 15 Jun 2026 16:16:25 +0200'));
        self::assertNull(SinceInput::timestamp(null));
        self::assertNull(SinceInput::timestamp(''));
    }
}
