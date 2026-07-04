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

use KonradMichalik\Typo3AiMate\Command\Support\LogTrimmer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * LogTrimmerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class LogTrimmerTest extends TestCase
{
    #[Test]
    public function messageIsCappedOnlyWhenItExceedsTheLimit(): void
    {
        self::assertSame('short', LogTrimmer::message('short', 100));

        $trimmed = LogTrimmer::message(str_repeat('x', 50), 10);
        self::assertSame('xxxxxxxxxx…[truncated]', $trimmed);
    }

    #[Test]
    public function entryCapsMessageAndTrace(): void
    {
        $entry = LogTrimmer::entry([
            'message' => str_repeat('m', 50),
            'trace' => str_repeat('t', 50),
            'level' => 'ERROR',
        ], 10, 20);

        $message = $entry['message'];
        $trace = $entry['trace'];
        self::assertIsString($message);
        self::assertIsString($trace);
        self::assertStringEndsWith('…[truncated]', $message);
        self::assertStringEndsWith('…[truncated]', $trace);
        // Untouched fields are preserved.
        self::assertSame('ERROR', $entry['level']);
    }

    #[Test]
    public function entryLeavesUnsensitiveTraceUntouchedWhenTraceLimitIsZero(): void
    {
        $trace = str_repeat('t', 5000);
        $entry = LogTrimmer::entry(['message' => 'ok', 'trace' => $trace], 100, 0);

        self::assertSame($trace, $entry['trace']);
    }

    #[Test]
    public function messageRedactsPersonalDataBeforeCapping(): void
    {
        $redacted = LogTrimmer::message('User jane@example.com from 10.0.0.5 failed', 1000);

        self::assertStringNotContainsString('jane@example.com', $redacted);
        self::assertStringNotContainsString('10.0.0.5', $redacted);
        self::assertStringContainsString('[redacted-email]', $redacted);
        self::assertStringContainsString('[redacted-ip]', $redacted);
    }

    #[Test]
    public function entryRedactsTheTraceEvenWithoutTruncation(): void
    {
        $entry = LogTrimmer::entry(['message' => 'ok', 'trace' => 'token=supersecretvalue in frame'], 100, 0);

        $trace = $entry['trace'];
        self::assertIsString($trace);
        self::assertStringNotContainsString('supersecretvalue', $trace);
        self::assertStringContainsString('token=[redacted]', $trace);
    }
}
