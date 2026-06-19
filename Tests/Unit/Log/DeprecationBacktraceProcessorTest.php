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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use TYPO3\CMS\Core\Core\{ApplicationContext, Environment};

/**
 * DeprecationBacktraceProcessorTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
final class DeprecationBacktraceProcessorTest extends TestCase
{
    private string $base;
    private DeprecationBacktraceProcessor $processor;

    protected function setUp(): void
    {
        // Distinct project and var paths (the shared WithTemporaryVarPath trait
        // makes them equal, which would defeat the var/ plumbing check).
        $this->base = sys_get_temp_dir().'/typo3-ai-mate-proc-'.bin2hex(random_bytes(8));
        mkdir($this->base.'/packages/my_ext/Classes', 0o777, true);
        mkdir($this->base.'/var/cache/code', 0o777, true);
        mkdir($this->base.'/vendor/typo3/cms-core', 0o777, true);
        // Real files so realpath resolves both sides consistently (macOS /private).
        touch($this->base.'/packages/my_ext/Classes/Caller.php');
        touch($this->base.'/packages/my_ext/Classes/NewsMiddleware.php');
        touch($this->base.'/var/cache/code/Template.php');
        touch($this->base.'/vendor/typo3/cms-core/Logger.php');

        Environment::initialize(
            new ApplicationContext('Testing'),
            true,
            false,
            $this->base,
            $this->base,
            $this->base.'/var',
            $this->base.'/config',
            '',
            'UNIX',
        );

        $this->processor = new DeprecationBacktraceProcessor();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->base);
    }

    #[Test]
    public function firstOwnFrameSkipsVendorFramesAndReturnsTheProjectRelativeOwnFrame(): void
    {
        $origin = $this->processor->firstOwnFrame([
            ['file' => $this->base.'/vendor/typo3/cms-core/Logger.php', 'line' => 10],
            ['file' => $this->base.'/packages/my_ext/Classes/Caller.php', 'line' => 42],
        ]);

        self::assertSame('packages/my_ext/Classes/Caller.php:42', $origin);
    }

    #[Test]
    public function firstOwnFrameReturnsNullWhenAllFramesAreVendor(): void
    {
        $origin = $this->processor->firstOwnFrame([
            ['file' => $this->base.'/vendor/typo3/cms-core/Logger.php', 'line' => 10],
        ]);

        self::assertNull($origin);
    }

    #[Test]
    public function firstOwnFrameIgnoresFramesWithoutFileOrLine(): void
    {
        $origin = $this->processor->firstOwnFrame([
            ['function' => 'call_user_func'],
            ['file' => $this->base.'/packages/my_ext/Classes/Caller.php'],
            ['file' => $this->base.'/packages/my_ext/Classes/Caller.php', 'line' => 7],
        ]);

        self::assertSame('packages/my_ext/Classes/Caller.php:7', $origin);
    }

    #[Test]
    public function firstOwnFrameReturnsNullWhenTheOnlyOwnFrameIsAMiddlewarePassThrough(): void
    {
        // The NewsMiddleware regression: $handler->handle() is the only own frame.
        $origin = $this->processor->firstOwnFrame([
            ['file' => $this->base.'/packages/my_ext/Classes/NewsMiddleware.php', 'line' => 28, 'class' => self::createStub(MiddlewareInterface::class)::class, 'function' => 'process'],
        ]);

        self::assertNull($origin);
    }

    #[Test]
    public function firstOwnFrameReturnsTheGenuineCallerBehindPlumbingFrames(): void
    {
        $origin = $this->processor->firstOwnFrame([
            ['file' => $this->base.'/packages/my_ext/Classes/NewsMiddleware.php', 'line' => 28, 'class' => self::createStub(MiddlewareInterface::class)::class, 'function' => 'process'],
            ['file' => $this->base.'/packages/my_ext/Classes/Caller.php', 'line' => 99, 'function' => 'doWork'],
        ]);

        self::assertSame('packages/my_ext/Classes/Caller.php:99', $origin);
    }

    #[Test]
    public function firstOwnFrameSkipsRequestHandlerPassThroughs(): void
    {
        $origin = $this->processor->firstOwnFrame([
            ['file' => $this->base.'/packages/my_ext/Classes/NewsMiddleware.php', 'line' => 5, 'class' => self::createStub(RequestHandlerInterface::class)::class, 'function' => 'handle'],
        ]);

        self::assertNull($origin);
    }

    #[Test]
    public function firstOwnFrameSkipsGeneratedCodeUnderTheVarPath(): void
    {
        // A compiled Fluid template under var/ is not source — skip it, keep the real caller.
        $origin = $this->processor->firstOwnFrame([
            ['file' => $this->base.'/var/cache/code/Template.php', 'line' => 120],
            ['file' => $this->base.'/packages/my_ext/Classes/Caller.php', 'line' => 8],
        ]);

        self::assertSame('packages/my_ext/Classes/Caller.php:8', $origin);
    }

    private function removeDir(string $dir): void
    {
        $entries = glob($dir.'/*') ?: [];
        foreach ($entries as $entry) {
            is_dir($entry) ? $this->removeDir($entry) : unlink($entry);
        }
        @rmdir($dir);
    }
}
