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

namespace KonradMichalik\Typo3AiMate\Support;

/**
 * ToolClusterGate.
 *
 * Reports whether the profiler/logs tool clusters currently have anything to
 * read, so a model can check first instead of finding out via a wasted call.
 *
 * Advisory only: every tool stays registered regardless of this state (ai-mate
 * v0.13 removed the discovery-time hook that could once suppress a whole
 * cluster), so an empty cluster's tools are always callable and simply come
 * back as an honest {@see \KonradMichalik\Typo3AiMate\Mate\ToolResult} miss.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ToolClusterGate
{
    /**
     * The profiler tool that turns the cluster on: without profiles there is
     * nothing to read yet, but there is always something to switch on.
     */
    public const PROFILER_ENTRY_TOOL = 'typo3-profiler-start';

    /**
     * The logs tool that answers "has anything been logged since?" first.
     */
    public const LOGS_ENTRY_TOOL = 'typo3-logs-tail';

    /**
     * @return array{registered: bool, reason: string}
     */
    public static function profiler(bool $profilesExist, bool $profilingActive): array
    {
        if ($profilesExist) {
            return ['registered' => true, 'reason' => 'recorded profiles exist'];
        }
        if ($profilingActive) {
            return ['registered' => true, 'reason' => 'profiling is currently active'];
        }

        return ['registered' => false, 'reason' => 'no profile has been recorded and profiling is off; call '.self::PROFILER_ENTRY_TOOL.' first'];
    }

    /**
     * @return array{registered: bool, reason: string}
     */
    public static function logs(bool $logHasEntries): array
    {
        return $logHasEntries
            ? ['registered' => true, 'reason' => 'the log has entries']
            : ['registered' => false, 'reason' => 'the log is empty; call '.self::LOGS_ENTRY_TOOL.' to check again once it is not'];
    }
}
