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

namespace KonradMichalik\Typo3AiMate\Mcp;

use KonradMichalik\Typo3AiMate\Mate\{ToolResult, Typo3CliRunner};
use Symfony\AI\Mate\Attribute\MateTool;

/**
 * SchedulerTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class SchedulerTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param int|null $uid return one task in detail, including its recurrence and last failure code
     */
    #[MateTool(
        name: 'typo3-scheduler',
        title: 'TYPO3 Scheduler',
        description: 'Scheduler task status: every registered task with uid, type, title, description, group, last and next execution, the last failure message and a flags list (disabled, group-hidden, failed, running, stale-running, invalid), plus when the scheduler itself last ran (schedulerLastRun). Answers "why did my task not run?". Task arguments are never returned. Read-only; running or editing a task is not exposed. Answers installed=false when EXT:scheduler is not installed.',
    )]
    public function tasks(?int $uid = null): string
    {
        $options = ['uid' => $uid];

        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:scheduler:tasks', [], $options));
    }
}
