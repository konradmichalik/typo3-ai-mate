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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Support;

use KonradMichalik\Typo3AiMate\Support\ToolClusterGate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ToolClusterGateTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ToolClusterGateTest extends TestCase
{
    #[Test]
    public function theProfilerClusterIsRegisteredOnceAProfileExists(): void
    {
        $gate = ToolClusterGate::profiler(profilesExist: true, profilingActive: false);

        self::assertTrue($gate['registered']);
        self::assertSame('recorded profiles exist', $gate['reason']);
    }

    #[Test]
    public function theProfilerClusterIsRegisteredWhileProfilingIsActiveEvenBeforeAnyProfileLands(): void
    {
        self::assertTrue(ToolClusterGate::profiler(profilesExist: false, profilingActive: true)['registered']);
    }

    #[Test]
    public function theProfilerClusterStaysUnregisteredWithNothingToReadAndProfilingOff(): void
    {
        $gate = ToolClusterGate::profiler(profilesExist: false, profilingActive: false);

        self::assertFalse($gate['registered']);
        self::assertStringContainsString(ToolClusterGate::PROFILER_ENTRY_TOOL, $gate['reason']);
    }

    #[Test]
    public function theLogsClusterFollowsWhetherAnythingWasLogged(): void
    {
        self::assertTrue(ToolClusterGate::logs(logHasEntries: true)['registered']);

        $gate = ToolClusterGate::logs(logHasEntries: false);
        self::assertFalse($gate['registered']);
        self::assertStringContainsString(ToolClusterGate::LOGS_ENTRY_TOOL, $gate['reason']);
    }
}
