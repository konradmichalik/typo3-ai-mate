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

namespace KonradMichalik\Typo3AiMate\Command;

use InvalidArgumentException;
use KonradMichalik\Typo3AiMate\Command\Support\{RecordQueryInput, SchedulerTaskFormatter};
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Scheduler\Domain\Repository\SchedulerTaskRepository;

use function count;
use function sprintf;

/**
 * SchedulerCommand.
 *
 * Reads through the core's SchedulerTaskRepository::getGroupedTasks(), the
 * same read-only listing the Scheduler module uses. findByUid() is avoided on
 * purpose: it disables a task it cannot restore, and the Scheduler class
 * itself clears stale running markers on construction.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:scheduler:tasks',
    description: 'List scheduler tasks with status, or one task in detail, as JSON.',
)]
final class SchedulerCommand extends AbstractJsonCommand
{
    /**
     * Minutes after which the core assumes a running execution is dead
     * (EXT:scheduler maxLifetime, same default as the core).
     */
    private const DEFAULT_MAX_LIFETIME = 1440;

    /**
     * @param object|null $taskRepository SchedulerTaskRepository, or null when EXT:scheduler is not installed
     */
    public function __construct(
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly Registry $registry,
        private readonly ?object $taskRepository = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('uid', null, InputOption::VALUE_REQUIRED, 'Return one task in detail');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->taskRepository instanceof SchedulerTaskRepository) {
            return $this->emit($output, [
                'installed' => false,
                '_hint' => 'EXT:scheduler is not installed, so there are no scheduler tasks.',
            ]);
        }

        try {
            $uid = RecordQueryInput::intOption($input->getOption('uid'), 'uid');
        } catch (InvalidArgumentException $exception) {
            return $this->emit($output, ['error' => $exception->getMessage()], Command::FAILURE);
        }

        // TaskService resolves task titles through $GLOBALS['LANG'], which v13 requires to be set.
        $GLOBALS['LANG'] ??= $this->languageServiceFactory->create('default');

        $now = time();
        $tasks = $this->collect(Cast::array($this->taskRepository->getGroupedTasks()), $now, null !== $uid);
        $result = [
            'installed' => true,
            'now' => date('c', $now),
            'schedulerLastRun' => SchedulerTaskFormatter::lastRun($this->registry->get('tx_scheduler', 'lastRun'), $now),
        ];

        return $this->emit($output, null === $uid ? $result + $this->listing($tasks) : $result + $this->single($tasks, $uid));
    }

    /**
     * @param array<mixed> $grouped result of getGroupedTasks()
     *
     * @return list<array<string, mixed>>
     */
    private function collect(array $grouped, int $now, bool $detail): array
    {
        $staleAfter = $this->maxLifetimeMinutes() * 60;
        $tasks = [];
        foreach (Cast::array($grouped['taskGroupsWithTasks'] ?? null) as $group) {
            $group = Cast::array($group);
            foreach (Cast::array($group['tasks'] ?? null) as $task) {
                $tasks[] = SchedulerTaskFormatter::task(Cast::array($task), $group, $now, $staleAfter, $detail);
            }
        }
        foreach (Cast::array($grouped['errorClasses'] ?? null) as $task) {
            $tasks[] = SchedulerTaskFormatter::invalidTask(Cast::array($task));
        }

        usort($tasks, static fn (array $a, array $b): int => $a['uid'] <=> $b['uid']);

        return $tasks;
    }

    /**
     * @param list<array<string, mixed>> $tasks
     *
     * @return array<string, mixed>
     */
    private function listing(array $tasks): array
    {
        return [
            'count' => count($tasks),
            '_hint' => 'A task with the running flag is skipped while it runs unless parallel execution is allowed; stale-running means the marker is older than the scheduler maxLifetime. Pass uid=<n> for one task with its recurrence.',
            'tasks' => $tasks,
        ];
    }

    /**
     * @param list<array<string, mixed>> $tasks
     *
     * @return array<string, mixed>
     */
    private function single(array $tasks, int $uid): array
    {
        foreach ($tasks as $task) {
            if ($task['uid'] === $uid) {
                return ['found' => true, 'task' => $task];
            }
        }

        return ['found' => false, '_hint' => sprintf('No scheduler task with uid %d exists (deleted tasks are not listed).', $uid)];
    }

    /**
     * Read from TYPO3_CONF_VARS rather than ExtensionConfiguration::get(),
     * which writes the configuration file when the entry is missing.
     */
    private function maxLifetimeMinutes(): int
    {
        $extensions = Cast::array(Cast::array($GLOBALS['TYPO3_CONF_VARS'] ?? null)['EXTENSIONS'] ?? null);
        $configured = Cast::int(Cast::array($extensions['scheduler'] ?? null)['maxLifetime'] ?? 0);

        return $configured > 0 ? $configured : self::DEFAULT_MAX_LIFETIME;
    }
}
