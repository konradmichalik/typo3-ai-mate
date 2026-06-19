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

namespace KonradMichalik\Typo3AiMate\Log;

use KonradMichalik\Typo3AiMate\Support\OwnPackages;
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\CMS\Core\Log\Processor\AbstractProcessor;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function is_a;
use function is_int;
use function is_string;
use function strlen;

/**
 * DeprecationBacktraceProcessor.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
final class DeprecationBacktraceProcessor extends AbstractProcessor
{
    public const DATA_KEY = 'typo3-ai-mate-origin';

    public function processLogRecord(LogRecord $logRecord): LogRecord
    {
        $data = $logRecord->getData();
        if (isset($data[self::DATA_KEY])) {
            return $logRecord;
        }

        $origin = $this->firstOwnFrame(debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS));
        if (null !== $origin) {
            $logRecord->setData([...$data, self::DATA_KEY => $origin]);
        }

        return $logRecord;
    }

    /**
     * First backtrace frame located in own (non-vendor) source code, as
     * "project-relative/path.php:line", or null if none. The backtrace is
     * ordered trigger-nearest first, so the first surviving frame is the closest
     * real caller; framework plumbing (PSR-15 dispatch, generated code) is
     * skipped so a routing pass-through is never mistaken for the caller.
     *
     * @param list<array<string, mixed>> $backtrace
     */
    public function firstOwnFrame(array $backtrace): ?string
    {
        foreach ($backtrace as $frame) {
            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
            $line = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : 0;
            if ('' === $file || 0 === $line || !OwnPackages::isOwn($file) || $this->isPlumbing($file, $frame)) {
                continue;
            }

            return $this->relative($file).':'.$line;
        }

        return null;
    }

    /**
     * Whether a frame is request-dispatch infrastructure rather than the code
     * that triggered the deprecation: generated code under the var/ path
     * (compiled Fluid templates, caches) or a PSR-15 middleware/request-handler
     * whose process()/handle() just routes the request.
     *
     * @param array<string, mixed> $frame
     */
    private function isPlumbing(string $file, array $frame): bool
    {
        if (str_starts_with($this->canonical($file).'/', $this->canonical(Environment::getVarPath()).'/')) {
            return true;
        }

        $class = is_string($frame['class'] ?? null) ? $frame['class'] : '';

        return '' !== $class
            && (is_a($class, MiddlewareInterface::class, true) || is_a($class, RequestHandlerInterface::class, true));
    }

    private function relative(string $file): string
    {
        $projectPath = $this->canonical(Environment::getProjectPath()).'/';
        $file = $this->canonical($file);

        return str_starts_with($file, $projectPath) ? substr($file, strlen($projectPath)) : $file;
    }

    private function canonical(string $path): string
    {
        $real = realpath($path);

        return GeneralUtility::fixWindowsFilePath(false !== $real ? $real : $path);
    }
}
