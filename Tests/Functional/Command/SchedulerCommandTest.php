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

namespace KonradMichalik\Typo3AiMate\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Execution;
use TYPO3\CMS\Scheduler\Task\ExecuteSchedulableCommandTask;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function array_column;

/**
 * SchedulerCommandTest.
 *
 * The fixture tasks are written straight into tx_scheduler_task, in the
 * storage format of the running core: v14 keeps the task type, parameters and
 * recurrence in columns, v13 a serialized task object.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class SchedulerCommandTest extends FunctionalTestCase
{
    private const COMMAND = 'typo3-ai-mate:records:query';

    protected array $coreExtensionsToLoad = [
        'install',
        'scheduler',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $now = time();

        $this->getConnectionPool()->getConnectionForTable('tx_scheduler_task_group')
            ->insert('tx_scheduler_task_group', ['uid' => 1, 'pid' => 0, 'groupName' => 'Nightly', 'hidden' => 0, 'deleted' => 0]);

        $this->insertTask(1, ['task_group' => 1, 'description' => 'Export', 'nextexecution' => $now + 3600, 'lastexecution_time' => $now - 3600]);
        $this->insertTask(2, ['disable' => 1, 'description' => 'Paused']);
        $this->insertTask(3, [
            'description' => 'Sync',
            'lastexecution_time' => $now - 600,
            'lastexecution_failure' => serialize(['code' => 1700000000, 'message' => 'Remote API rejected token: fixture-failure-token', 'file' => '', 'line' => 0, 'traceString' => '']),
        ]);
        $this->insertTask(4, [
            'description' => 'Import',
            'lastexecution_time' => $now - 2 * 86400,
            'serialized_executions' => serialize([$now - 2 * 86400]),
        ]);
        $this->insertInvalidTask(5);
    }

    #[Test]
    public function listsTasksWithDisabledFailedAndStuckRunningFlags(): void
    {
        [$exitCode, $result] = $this->runCommand([]);

        self::assertSame(0, $exitCode);
        self::assertTrue($result['installed']);
        self::assertSame(5, $result['count']);

        $byUid = array_column($result['tasks'], null, 'uid');
        // v13 reports a command task by its task class, v14 by the command identifier.
        self::assertSame($this->isV14() ? self::COMMAND : ExecuteSchedulableCommandTask::class, $byUid[1]['type']);
        self::assertSame('Nightly', $byUid[1]['group']);
        self::assertSame([], $byUid[1]['flags']);
        self::assertSame(['disabled'], $byUid[2]['flags']);
        self::assertSame(['failed'], $byUid[3]['flags']);
        self::assertStringContainsString('Remote API rejected', $byUid[3]['failure']);
        self::assertSame(['running', 'stale-running'], $byUid[4]['flags']);
        self::assertSame(['invalid'], $byUid[5]['flags']);
    }

    #[Test]
    public function credentialLikeTaskArgumentsAndFailureTokensAreNotInTheOutput(): void
    {
        $tester = new CommandTester($this->get(CommandRegistry::class)->get('typo3-ai-mate:scheduler:tasks'));
        $tester->execute([]);
        $list = $tester->getDisplay();
        $tester->execute(['--uid' => '1']);
        $detail = $tester->getDisplay();

        foreach ([$list, $detail] as $display) {
            self::assertStringContainsString('"uid": 1', $display);
            self::assertStringNotContainsString('fixture-plain-secret', $display);
            self::assertStringNotContainsString('fixture-api-key-123', $display);
            self::assertStringNotContainsString('fixture-failure-token', $display);
        }
    }

    #[Test]
    public function reportsOneTaskWithItsRecurrence(): void
    {
        [$exitCode, $result] = $this->runCommand(['--uid' => '1']);

        self::assertSame(0, $exitCode);
        self::assertTrue($result['found']);
        self::assertSame(1, $result['task']['uid']);
        self::assertSame('recurring', $result['task']['recurrence']['type']);
        self::assertSame(3600, (int) $result['task']['recurrence']['frequency']);
    }

    #[Test]
    public function reportsAnUnknownUidAsNotFound(): void
    {
        [$exitCode, $result] = $this->runCommand(['--uid' => '99']);

        self::assertSame(0, $exitCode);
        self::assertFalse($result['found']);
    }

    #[Test]
    public function reportsWhetherTheSchedulerHasRun(): void
    {
        [, $before] = $this->runCommand([]);
        self::assertFalse($before['schedulerLastRun']['recorded']);

        $now = time();
        $this->get(Registry::class)->set('tx_scheduler', 'lastRun', ['start' => $now - 30, 'end' => $now - 20, 'type' => 'cron']);

        [, $after] = $this->runCommand([]);
        self::assertTrue($after['schedulerLastRun']['recorded']);
        self::assertSame('cron', $after['schedulerLastRun']['type']);
    }

    #[Test]
    public function rejectsANonNumericUid(): void
    {
        [$exitCode, $result] = $this->runCommand(['--uid' => 'abc']);

        self::assertSame(1, $exitCode);
        self::assertArrayHasKey('error', $result);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $tester = new CommandTester($this->get(CommandRegistry::class)->get('typo3-ai-mate:scheduler:tasks'));
        $exitCode = $tester->execute($input);
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        /* @var array<string, mixed> $decoded */
        return [$exitCode, $decoded];
    }

    /**
     * A command task whose argument and option carry invented credentials.
     *
     * @param array<string, mixed> $fields
     */
    private function insertTask(int $uid, array $fields): void
    {
        $arguments = ['table' => 'fixture-plain-secret'];
        $options = ['where' => true];
        $optionValues = ['where' => 'apiKey=fixture-api-key-123'];

        if ($this->isV14()) {
            $row = [
                'tasktype' => self::COMMAND,
                'parameters' => ['arguments' => $arguments, 'options' => $options, 'optionValues' => $optionValues],
                'execution_details' => ['start' => time() - 86400, 'end' => 0, 'interval' => 3600, 'multiple' => false, 'cronCmd' => '', 'isNewSingleExecution' => false],
            ];
        } else {
            // v13 API. Its constructor would instantiate the Scheduler, which clears execution markers.
            $task = (new ReflectionClass(ExecuteSchedulableCommandTask::class))->newInstanceWithoutConstructor();
            $execution = new Execution();
            $execution->setStart(time() - 86400);
            $execution->setInterval(3600);
            $execution->setMultiple(false);
            $task->setExecution($execution);
            $task->setTaskUid($uid);
            $task->setCommandIdentifier(self::COMMAND);
            $task->setArguments($arguments);
            $task->setOptions($options);
            $task->setOptionValues($optionValues);
            $row = ['serialized_task_object' => serialize($task)];
        }

        $this->writeTask($uid, $row + $fields);
    }

    private function insertInvalidTask(int $uid): void
    {
        $row = $this->isV14()
            ? ['tasktype' => 'fixture:gone']
            : ['serialized_task_object' => 'O:21:"Fixture\\Gone\\GoneTask":0:{}'];

        $this->writeTask($uid, $row + ['description' => 'Gone']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function writeTask(int $uid, array $row): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_scheduler_task')->insert(
            'tx_scheduler_task',
            $row + [
                'uid' => $uid,
                'crdate' => time(),
                'disable' => 0,
                'deleted' => 0,
                'task_group' => 0,
                'nextexecution' => 0,
                'lastexecution_time' => 0,
                'lastexecution_failure' => '',
                'lastexecution_context' => 'CLI',
                'serialized_executions' => '',
            ],
            ['serialized_executions' => Connection::PARAM_LOB],
        );
    }

    private function isV14(): bool
    {
        return GeneralUtility::makeInstance(Typo3Version::class)->getMajorVersion() >= 14;
    }
}
