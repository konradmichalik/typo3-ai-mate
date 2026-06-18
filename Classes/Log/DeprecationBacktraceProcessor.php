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
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\CMS\Core\Log\Processor\AbstractProcessor;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
     * First backtrace frame located in own (non-vendor) code, as
     * "project-relative/path.php:line", or null if none.
     *
     * @param list<array<string, mixed>> $backtrace
     */
    public function firstOwnFrame(array $backtrace): ?string
    {
        foreach ($backtrace as $frame) {
            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
            $line = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : 0;
            if ('' === $file || 0 === $line || !OwnPackages::isOwn($file)) {
                continue;
            }

            return $this->relative($file).':'.$line;
        }

        return null;
    }

    private function relative(string $file): string
    {
        $file = GeneralUtility::fixWindowsFilePath($file);
        $projectPath = GeneralUtility::fixWindowsFilePath(Environment::getProjectPath()).'/';

        return str_starts_with($file, $projectPath) ? substr($file, strlen($projectPath)) : $file;
    }
}
