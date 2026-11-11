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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command\Support;

use KonradMichalik\Typo3AiMate\Command\Support\SchedulerTaskFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function json_encode;

/**
 * SchedulerTaskFormatterTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class SchedulerTaskFormatterTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const STALE_AFTER = 86_400;

    #[Test]
    public function v14TaskIsMappedToTheCompactListShape(): void
    {
        $task = SchedulerTaskFormatter::task($this->v14Task(), ['groupName' => 'Nightly', 'hidden' => 0], self::NOW, self::STALE_AFTER, false);

        self::assertSame([
            'uid' => 7,
            'type' => 'typo3-ai-mate:records:query',
            'title' => 'Records query [typo3_ai_mate]',
            'description' => 'Nightly export',
            'group' => 'Nightly',
            'flags' => [],
            'lastExecution' => date('c', self::NOW - 3600),
            'nextExecution' => date('c', self::NOW + 3600),
            'failure' => null,
        ], $task);
    }

    #[Test]
    public function v13TaskKeysResolveToTheSameFields(): void
    {
        $task = SchedulerTaskFormatter::task([
            'uid' => 3,
            'class' => 'Vendor\\Ext\\Task\\ImportTask',
            'classTitle' => 'Import',
            'description' => '',
            'disabled' => false,
            'isRunning' => false,
            'nextExecution' => 0,
            'lastExecutionTime' => 0,
            'type' => 'recurring',
            'frequency' => '*/5 * * * *',
            'multiple' => true,
            'lastExecutionFailure' => false,
        ], [], self::NOW, self::STALE_AFTER, true);

        self::assertSame('Vendor\\Ext\\Task\\ImportTask', $task['type']);
        self::assertSame('Import', $task['title']);
        self::assertNull($task['group']);
        self::assertNull($task['lastExecution']);
        self::assertNull($task['nextExecution']);
        self::assertSame(['type' => 'recurring', 'frequency' => '*/5 * * * *', 'parallelExecutionAllowed' => true], $task['recurrence']);
    }

    #[Test]
    public function disabledFailedAndHiddenGroupTasksAreFlagged(): void
    {
        $task = SchedulerTaskFormatter::task([
            ...$this->v14Task(),
            'disabled' => true,
            'lastExecutionFailure' => true,
            'lastExecutionFailureCode' => 1700000000,
            'lastExecutionFailureMessage' => 'Remote API rejected the request',
        ], ['groupName' => 'Paused', 'hidden' => 1], self::NOW, self::STALE_AFTER, true);

        self::assertSame(['disabled', 'group-hidden', 'failed'], $task['flags']);
        self::assertSame('Remote API rejected the request', $task['failure']);
        self::assertSame(1700000000, $task['failureCode']);
    }

    #[Test]
    public function runningMarkerOlderThanTheMaxLifetimeIsFlaggedStale(): void
    {
        $fresh = SchedulerTaskFormatter::task([...$this->v14Task(), 'isRunning' => true, 'lastExecutionTime' => self::NOW - 60], [], self::NOW, self::STALE_AFTER, true);
        $stuck = SchedulerTaskFormatter::task([...$this->v14Task(), 'isRunning' => true, 'lastExecutionTime' => self::NOW - self::STALE_AFTER - 1], [], self::NOW, self::STALE_AFTER, true);

        self::assertSame(['running'], $fresh['flags']);
        self::assertSame(date('c', self::NOW - 60), $fresh['runningSince']);
        self::assertSame(['running', 'stale-running'], $stuck['flags']);
    }

    #[Test]
    public function taskArgumentsAndAdditionalInformationNeverReachTheOutput(): void
    {
        $task = [
            ...$this->v14Task(),
            'additionalInformation' => 'typo3-ai-mate:records:query fixture-plain-secret --where=apiKey=fixture-api-key-123',
            'parameters' => ['arguments' => ['table' => 'fixture-plain-secret']],
            'arguments' => ['token' => 'fixture-plain-secret'],
            'description' => 'Sync with password=fixture-description-pass',
            'lastExecutionFailure' => true,
            'lastExecutionFailureMessage' => 'Login failed for token: fixture-failure-token',
        ];

        $encoded = (string) json_encode([
            SchedulerTaskFormatter::task($task, [], self::NOW, self::STALE_AFTER, false),
            SchedulerTaskFormatter::task($task, [], self::NOW, self::STALE_AFTER, true),
        ]);

        self::assertStringNotContainsString('fixture-plain-secret', $encoded);
        self::assertStringNotContainsString('fixture-api-key-123', $encoded);
        self::assertStringNotContainsString('fixture-description-pass', $encoded);
        self::assertStringNotContainsString('fixture-failure-token', $encoded);
        self::assertStringContainsString('[redacted]', $encoded);
    }

    #[Test]
    public function invalidTaskKeepsItsTypeAndARedactedError(): void
    {
        $task = SchedulerTaskFormatter::invalidTask([
            'uid' => 9,
            'taskType' => 'gone:command',
            'description' => 'Old',
            'errorMessage' => 'Task type gone:command not found. secret=fixture-error-secret',
            'lastExecutionTime' => 0,
        ]);

        self::assertSame(9, $task['uid']);
        self::assertSame('gone:command', $task['type']);
        self::assertSame(['invalid'], $task['flags']);
        self::assertIsString($task['error']);
        self::assertStringNotContainsString('fixture-error-secret', $task['error']);
    }

    #[Test]
    public function lastRunReportsAMissingRegistryEntryAsNotRecorded(): void
    {
        self::assertSame(['recorded' => false], SchedulerTaskFormatter::lastRun(null, self::NOW));
        self::assertSame(['recorded' => false], SchedulerTaskFormatter::lastRun([], self::NOW));
    }

    #[Test]
    public function lastRunReportsStartEndTypeAndAge(): void
    {
        self::assertSame([
            'recorded' => true,
            'type' => 'cron',
            'start' => date('c', self::NOW - 120),
            'end' => date('c', self::NOW - 100),
            'secondsAgo' => 100,
        ], SchedulerTaskFormatter::lastRun(['start' => self::NOW - 120, 'end' => self::NOW - 100, 'type' => 'cron'], self::NOW));
    }

    /**
     * @return array<string, mixed>
     */
    private function v14Task(): array
    {
        return [
            'uid' => 7,
            'taskType' => 'typo3-ai-mate:records:query',
            'fullTitle' => 'Records query [typo3_ai_mate]',
            'description' => 'Nightly export',
            'disabled' => false,
            'isRunning' => false,
            'nextExecution' => self::NOW + 3600,
            'lastExecutionTime' => self::NOW - 3600,
            'lastExecutionContext' => 'CLI',
            'runningType' => 'recurring',
            'frequency' => 3600,
            'multiple' => false,
            'priority' => 50,
            'lastExecutionFailure' => false,
        ];
    }
}
