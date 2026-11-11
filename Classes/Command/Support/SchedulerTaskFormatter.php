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

namespace KonradMichalik\Typo3AiMate\Command\Support;

use KonradMichalik\Typo3AiMate\Support\{Cast, Redactor};

use function is_array;

/**
 * SchedulerTaskFormatter.
 *
 * Maps one entry of the core's SchedulerTaskRepository::getGroupedTasks() to
 * the tool's output. The fields are picked by name, so the task's arguments
 * (and getAdditionalInformation(), which renders them for the backend module)
 * never reach the output: they routinely carry API keys and passwords. Free
 * text that does pass (description, failure message) goes through
 * {@see Redactor}. The v13 and v14 key names differ and are both read.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class SchedulerTaskFormatter
{
    private const TEXT_LIMIT = 300;

    /**
     * @param array<mixed> $task  task entry of getGroupedTasks()
     * @param array<mixed> $group the group entry the task is listed under
     *
     * @return array<string, mixed>
     */
    public static function task(array $task, array $group, int $now, int $staleAfter, bool $detail): array
    {
        $failed = (bool) ($task['lastExecutionFailure'] ?? false);
        $running = (bool) ($task['isRunning'] ?? false);
        $lastExecution = Cast::int($task['lastExecutionTime'] ?? 0);
        $groupName = Cast::string($group['groupName'] ?? '');

        $result = [
            'uid' => Cast::int($task['uid'] ?? 0),
            'type' => self::type($task),
            'title' => Cast::string($task['fullTitle'] ?? $task['classTitle'] ?? ''),
            'description' => self::text($task['description'] ?? ''),
            'group' => '' === $groupName ? null : $groupName,
            'flags' => self::flags($task, $group, $now, $staleAfter),
            'lastExecution' => self::date($lastExecution),
            'nextExecution' => self::date(Cast::int($task['nextExecution'] ?? 0)),
            'failure' => $failed ? self::text($task['lastExecutionFailureMessage'] ?? '') : null,
        ];
        if ($running) {
            $result['runningSince'] = self::date($lastExecution);
        }

        return $detail ? $result + self::details($task, $failed) : $result;
    }

    /**
     * A task the core could not restore (class gone, task type no longer
     * registered): the scheduler skips it, so it is reported with its error.
     *
     * @param array<mixed> $task entry of getGroupedTasks()['errorClasses']
     *
     * @return array<string, mixed>
     */
    public static function invalidTask(array $task): array
    {
        return [
            'uid' => Cast::int($task['uid'] ?? 0),
            'type' => self::type($task),
            'description' => self::text($task['description'] ?? ''),
            'flags' => ['invalid'],
            'lastExecution' => self::date(Cast::int($task['lastExecutionTime'] ?? 0)),
            'error' => self::text($task['errorMessage'] ?? ''),
        ];
    }

    /**
     * @param mixed $lastRun the registry entry tx_scheduler/lastRun
     *
     * @return array<string, mixed>
     */
    public static function lastRun(mixed $lastRun, int $now): array
    {
        if (!is_array($lastRun) || [] === $lastRun) {
            return ['recorded' => false];
        }

        $end = Cast::int($lastRun['end'] ?? 0);

        return [
            'recorded' => true,
            'type' => Cast::string($lastRun['type'] ?? ''),
            'start' => self::date(Cast::int($lastRun['start'] ?? 0)),
            'end' => self::date($end),
            'secondsAgo' => 0 === $end ? null : $now - $end,
        ];
    }

    /**
     * @param array<mixed> $task
     * @param array<mixed> $group
     *
     * @return list<string>
     */
    private static function flags(array $task, array $group, int $now, int $staleAfter): array
    {
        $flags = [];
        if ((bool) ($task['disabled'] ?? false)) {
            $flags[] = 'disabled';
        }
        if ((bool) ($group['hidden'] ?? false)) {
            $flags[] = 'group-hidden';
        }
        if ((bool) ($task['lastExecutionFailure'] ?? false)) {
            $flags[] = 'failed';
        }
        if ((bool) ($task['isRunning'] ?? false)) {
            $flags[] = 'running';
            // The core's own threshold (maxLifetime) for assuming the process is dead.
            if ($now - Cast::int($task['lastExecutionTime'] ?? 0) > $staleAfter) {
                $flags[] = 'stale-running';
            }
        }

        return $flags;
    }

    /**
     * @param array<mixed> $task
     *
     * @return array<string, mixed>
     */
    private static function details(array $task, bool $failed): array
    {
        $details = [
            'lastExecutionContext' => Cast::string($task['lastExecutionContext'] ?? ''),
            'recurrence' => [
                'type' => Cast::string($task['runningType'] ?? $task['type'] ?? ''),
                'frequency' => $task['frequency'] ?? '',
                'parallelExecutionAllowed' => (bool) ($task['multiple'] ?? false),
            ],
        ];
        if (isset($task['priority'])) {
            $details['priority'] = Cast::int($task['priority']);
        }
        if ($failed) {
            $details['failureCode'] = Cast::int($task['lastExecutionFailureCode'] ?? 0);
        }

        return $details;
    }

    /**
     * v14 names the type `taskType` (a class name or a command identifier),
     * v13 the task class `class`. v13 also uses `type`, for single/recurring.
     *
     * @param array<mixed> $task
     */
    private static function type(array $task): string
    {
        return Cast::string($task['taskType'] ?? $task['class'] ?? '');
    }

    private static function text(mixed $value): string
    {
        return RecordTrimmer::truncate(Redactor::redact(Cast::string($value)), self::TEXT_LIMIT);
    }

    private static function date(int $timestamp): ?string
    {
        return 0 === $timestamp ? null : date('c', $timestamp);
    }
}
