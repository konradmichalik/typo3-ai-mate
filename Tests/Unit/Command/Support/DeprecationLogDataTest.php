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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command\Support;

use KonradMichalik\Typo3AiMate\Command\Support\DeprecationLogData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DeprecationLogDataTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
final class DeprecationLogDataTest extends TestCase
{
    #[Test]
    public function originReadsTheProcessorKeyAndUnescapesSlashes(): void
    {
        $message = 'Foo is deprecated - {"typo3-ai-mate-origin":"packages\\/my_ext\\/Classes\\/Foo.php:42"}';

        self::assertSame('packages/my_ext/Classes/Foo.php:42', DeprecationLogData::origin($message));
    }

    #[Test]
    public function originIsNullWhenAbsent(): void
    {
        self::assertNull(DeprecationLogData::origin('Just a deprecation message'));
    }

    #[Test]
    public function withoutDataStripsTheTrailingDataTail(): void
    {
        $message = 'Foo is deprecated - {"typo3-ai-mate-origin":"packages\\/x.php:1"}';

        self::assertSame('Foo is deprecated', DeprecationLogData::withoutData($message));
    }

    #[Test]
    public function withoutDataLeavesAPlainMessageUntouched(): void
    {
        self::assertSame('Just a deprecation message', DeprecationLogData::withoutData('Just a deprecation message'));
    }
}
