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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Log;

use KonradMichalik\Typo3AiMate\Log\DeprecationBacktraceProcessor;
use KonradMichalik\Typo3AiMate\Tests\Unit\Command\WithTemporaryVarPath;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DeprecationBacktraceProcessorTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
final class DeprecationBacktraceProcessorTest extends TestCase
{
    use WithTemporaryVarPath;

    private string $projectPath;
    private DeprecationBacktraceProcessor $processor;

    protected function setUp(): void
    {
        $this->initVarPath();
        $this->projectPath = $this->varPath;
        // Real files so realpath resolves both sides consistently (macOS /private).
        mkdir($this->projectPath.'/vendor/typo3/cms-core', 0o777, true);
        mkdir($this->projectPath.'/packages/my_ext/Classes', 0o777, true);
        touch($this->projectPath.'/vendor/typo3/cms-core/Logger.php');
        touch($this->projectPath.'/packages/my_ext/Classes/Caller.php');
        $this->processor = new DeprecationBacktraceProcessor();
    }

    protected function tearDown(): void
    {
        foreach ([
            'vendor/typo3/cms-core/Logger.php', 'packages/my_ext/Classes/Caller.php',
            'vendor/typo3/cms-core', 'vendor/typo3', 'vendor',
            'packages/my_ext/Classes', 'packages/my_ext', 'packages',
        ] as $relative) {
            @unlink($this->projectPath.'/'.$relative);
            @rmdir($this->projectPath.'/'.$relative);
        }
        $this->cleanupVarPath();
    }

    #[Test]
    public function firstOwnFrameSkipsVendorFramesAndReturnsTheProjectRelativeOwnFrame(): void
    {
        $origin = $this->processor->firstOwnFrame([
            ['file' => $this->projectPath.'/vendor/typo3/cms-core/Logger.php', 'line' => 10],
            ['file' => $this->projectPath.'/packages/my_ext/Classes/Caller.php', 'line' => 42],
        ]);

        self::assertSame('packages/my_ext/Classes/Caller.php:42', $origin);
    }

    #[Test]
    public function firstOwnFrameReturnsNullWhenAllFramesAreVendor(): void
    {
        $origin = $this->processor->firstOwnFrame([
            ['file' => $this->projectPath.'/vendor/typo3/cms-core/Logger.php', 'line' => 10],
        ]);

        self::assertNull($origin);
    }

    #[Test]
    public function firstOwnFrameIgnoresFramesWithoutFileOrLine(): void
    {
        $origin = $this->processor->firstOwnFrame([
            ['function' => 'call_user_func'],
            ['file' => $this->projectPath.'/packages/my_ext/Classes/Caller.php'],
            ['file' => $this->projectPath.'/packages/my_ext/Classes/Caller.php', 'line' => 7],
        ]);

        self::assertSame('packages/my_ext/Classes/Caller.php:7', $origin);
    }
}
