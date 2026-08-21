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
 * Decides which tool clusters are worth putting in front of the model. Seven
 * profiler tools and three logs tools were offered across a whole benchmark run
 * in which not one of them was called, because there were no profiles in
 * `var/log/profiles/` and nothing worth reading in the log. Their names still
 * lengthened the list every tool search had to work through.
 *
 * Not deletion: a cluster whose subject does not exist yet collapses to the one
 * tool that brings it into existence, and comes back whole as soon as it does.
 * The rules are pure functions of runtime state so both processes — the Mate
 * server that registers the tools and the TYPO3 command that reports the state —
 * derive the same answer from the same rule.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ToolClusterGate
{
    /**
     * The one profiler tool that stays: without profiles there is nothing to
     * read, but there is always something to switch on.
     */
    public const PROFILER_ENTRY_TOOL = 'typo3-profiler-start';

    /**
     * @var list<string>
     */
    public const PROFILER_TOOLS = [
        'typo3-profiler-latest',
        'typo3-profiler-list',
        'typo3-profiler-search',
        'typo3-profiler-get',
        'typo3-profiler-stop',
        'typo3-profiler-status',
    ];

    /**
     * The one logs tool that stays, so "has anything been logged since?" is still
     * one call away.
     */
    public const LOGS_ENTRY_TOOL = 'typo3-logs-tail';

    /**
     * @var list<string>
     */
    public const LOGS_TOOLS = [
        'typo3-logs-search',
        'typo3-logs-by-level',
    ];

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

        return ['registered' => false, 'reason' => 'no profile has been recorded and profiling is off; only '.self::PROFILER_ENTRY_TOOL.' is offered'];
    }

    /**
     * @return array{registered: bool, reason: string}
     */
    public static function logs(bool $logHasEntries): array
    {
        return $logHasEntries
            ? ['registered' => true, 'reason' => 'the log has entries']
            : ['registered' => false, 'reason' => 'the log is empty; only '.self::LOGS_ENTRY_TOOL.' is offered'];
    }
}
