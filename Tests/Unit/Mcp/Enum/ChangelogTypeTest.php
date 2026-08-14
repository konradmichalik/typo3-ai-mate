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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Mcp\Enum;

use KonradMichalik\Typo3AiMate\Mcp\Enum\ChangelogType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ChangelogTypeTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ChangelogTypeTest extends TestCase
{
    #[Test]
    public function isUnsupportedIsFalseForAnOmittedValue(): void
    {
        self::assertFalse(ChangelogType::isUnsupported(''));
    }

    #[Test]
    public function isUnsupportedIsFalseForEachKnownCase(): void
    {
        self::assertFalse(ChangelogType::isUnsupported('Breaking'));
        self::assertFalse(ChangelogType::isUnsupported('Deprecation'));
        self::assertFalse(ChangelogType::isUnsupported('Feature'));
        self::assertFalse(ChangelogType::isUnsupported('Important'));
    }

    #[Test]
    public function isUnsupportedIsTrueForATypo(): void
    {
        self::assertTrue(ChangelogType::isUnsupported('Breakng'));
    }
}
