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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Log;

use KonradMichalik\Ttt\Attribute\WithEnvironment;
use KonradMichalik\Typo3AiMate\Log\DeprecationBacktraceProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Log\LogRecord;

/**
 * DeprecationBacktraceProcessorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[WithEnvironment]
final class DeprecationBacktraceProcessorTest extends TestCase
{
    private ?string $base = null;
    private DeprecationBacktraceProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new DeprecationBacktraceProcessor();
    }

    #[Test]
    public function firstOwnFrameSkipsVendorFramesAndReturnsTheProjectRelativeOwnFrame(): void
    {
        $base = $this->base();

        $origin = $this->processor->firstOwnFrame([
            ['file' => $base.'/vendor/typo3/cms-core/Logger.php', 'line' => 10],
            ['file' => $base.'/packages/my_ext/Classes/Caller.php', 'line' => 42],
        ]);

        self::assertSame('packages/my_ext/Classes/Caller.php:42', $origin);
    }

    #[Test]
    public function firstOwnFrameReturnsNullWhenAllFramesAreVendor(): void
    {
        $base = $this->base();

        $origin = $this->processor->firstOwnFrame([
            ['file' => $base.'/vendor/typo3/cms-core/Logger.php', 'line' => 10],
        ]);

        self::assertNull($origin);
    }

    #[Test]
    public function firstOwnFrameIgnoresFramesWithoutFileOrLine(): void
    {
        $base = $this->base();

        $origin = $this->processor->firstOwnFrame([
            ['function' => 'call_user_func'],
            ['file' => $base.'/packages/my_ext/Classes/Caller.php'],
            ['file' => $base.'/packages/my_ext/Classes/Caller.php', 'line' => 7],
        ]);

        self::assertSame('packages/my_ext/Classes/Caller.php:7', $origin);
    }

    #[Test]
    public function firstOwnFrameReturnsNullWhenTheOnlyOwnFrameIsAMiddlewarePassThrough(): void
    {
        $base = $this->base();

        // The NewsMiddleware regression: $handler->handle() is the only own frame.
        $origin = $this->processor->firstOwnFrame([
            ['file' => $base.'/packages/my_ext/Classes/NewsMiddleware.php', 'line' => 28, 'class' => self::createStub(MiddlewareInterface::class)::class, 'function' => 'process'],
        ]);

        self::assertNull($origin);
    }

    #[Test]
    public function firstOwnFrameReturnsTheGenuineCallerBehindPlumbingFrames(): void
    {
        $base = $this->base();

        $origin = $this->processor->firstOwnFrame([
            ['file' => $base.'/packages/my_ext/Classes/NewsMiddleware.php', 'line' => 28, 'class' => self::createStub(MiddlewareInterface::class)::class, 'function' => 'process'],
            ['file' => $base.'/packages/my_ext/Classes/Caller.php', 'line' => 99, 'function' => 'doWork'],
        ]);

        self::assertSame('packages/my_ext/Classes/Caller.php:99', $origin);
    }

    #[Test]
    public function firstOwnFrameSkipsRequestHandlerPassThroughs(): void
    {
        $base = $this->base();

        $origin = $this->processor->firstOwnFrame([
            ['file' => $base.'/packages/my_ext/Classes/NewsMiddleware.php', 'line' => 5, 'class' => self::createStub(RequestHandlerInterface::class)::class, 'function' => 'handle'],
        ]);

        self::assertNull($origin);
    }

    #[Test]
    public function firstOwnFrameSkipsTheFrontControllerEntryScript(): void
    {
        $this->base();

        // A vendor-only deprecation leaves only the public/index.php Application->run()
        // frame as "own" — it bootstraps the request and must not be reported.
        $origin = $this->processor->firstOwnFrame([
            ['file' => Environment::getPublicPath().'/index.php', 'line' => 28, 'class' => 'TYPO3\\CMS\\Frontend\\Http\\Application', 'function' => 'run'],
        ]);

        self::assertNull($origin);
    }

    #[Test]
    public function firstOwnFrameKeepsNonDispatchMethodsOnPsr15Classes(): void
    {
        $base = $this->base();

        // A deprecation triggered in a middleware's own helper (not process()) is
        // a genuine caller and must not be hidden as plumbing.
        $origin = $this->processor->firstOwnFrame([
            ['file' => $base.'/packages/my_ext/Classes/NewsMiddleware.php', 'line' => 51, 'class' => self::createStub(MiddlewareInterface::class)::class, 'function' => 'enrichRequest'],
        ]);

        self::assertSame('packages/my_ext/Classes/NewsMiddleware.php:51', $origin);
    }

    #[Test]
    public function firstOwnFrameSkipsGeneratedCodeUnderTheVarPath(): void
    {
        $base = $this->base();

        // A compiled Fluid template under var/ is not source — skip it, keep the real caller.
        $origin = $this->processor->firstOwnFrame([
            ['file' => $base.'/var/cache/code/Template.php', 'line' => 120],
            ['file' => $base.'/packages/my_ext/Classes/Caller.php', 'line' => 8],
        ]);

        self::assertSame('packages/my_ext/Classes/Caller.php:8', $origin);
    }

    #[Test]
    public function processLogRecordTagsAnUntaggedRecordWithAnOrigin(): void
    {
        $record = new LogRecord('TYPO3.CMS.deprecations', LogLevel::NOTICE, 'is deprecated');

        $result = $this->processor->processLogRecord($record);

        self::assertArrayHasKey(DeprecationBacktraceProcessor::DATA_KEY, $result->getData());
    }

    #[Test]
    public function processLogRecordLeavesAnAlreadyTaggedRecordUntouched(): void
    {
        $record = new LogRecord('TYPO3.CMS.deprecations', LogLevel::NOTICE, 'is deprecated', [
            DeprecationBacktraceProcessor::DATA_KEY => 'packages/my_ext/Classes/Caller.php:1',
        ]);

        $result = $this->processor->processLogRecord($record);

        self::assertSame('packages/my_ext/Classes/Caller.php:1', $result->getData()[DeprecationBacktraceProcessor::DATA_KEY]);
    }

    /**
     * Lazily bootstraps the fixture files under Environment::getProjectPath().
     *
     * Must be called from within a test method, not setUp(): ttt applies
     * #[WithEnvironment] after setUp() runs, so setUp() would still observe
     * the pre-sandbox Environment. See vendor/konradmichalik/ttt/docs/lifecycle.md.
     */
    private function base(): string
    {
        if (null !== $this->base) {
            return $this->base;
        }

        // WithEnvironment provides distinct project, public and var paths - the
        // var/ plumbing check relies on var/ not being equal to the project root.
        $this->base = Environment::getProjectPath();
        mkdir($this->base.'/packages/my_ext/Classes', 0o777, true);
        mkdir($this->base.'/var/cache/code', 0o777, true);
        mkdir($this->base.'/vendor/typo3/cms-core', 0o777, true);
        // Real files so realpath resolves both sides consistently (macOS /private).
        touch($this->base.'/packages/my_ext/Classes/Caller.php');
        touch($this->base.'/packages/my_ext/Classes/NewsMiddleware.php');
        touch($this->base.'/var/cache/code/Template.php');
        touch($this->base.'/vendor/typo3/cms-core/Logger.php');
        touch(Environment::getPublicPath().'/index.php'); // front controller (public path entry)

        return $this->base;
    }
}
