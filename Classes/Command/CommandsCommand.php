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

use KonradMichalik\Typo3AiMate\Support\OwnPackages;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Core\Console\CommandRegistry;

use function count;
use function is_string;
use function sort;
use function str_contains;

/**
 * CommandsCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:commands:list',
    description: 'All registered console commands (name, description, synopsis) as JSON.',
)]
final class CommandsCommand extends AbstractJsonCommand
{
    public function __construct(private readonly CommandRegistry $commandRegistry)
    {
        parent::__construct();
    }

    /**
     * @return array{name: string, description: string, synopsis: string}
     */
    public function describe(Command $command, string $name): array
    {
        return [
            'name' => $name,
            'description' => $command->getDescription(),
            'synopsis' => $command->getSynopsis(true),
        ];
    }

    public function isOwnCommand(Command $command): bool
    {
        $file = (new ReflectionClass($command))->getFileName();

        return false !== $file && OwnPackages::isOwn($file);
    }

    protected function configure(): void
    {
        $this
            ->addOption('pattern', null, InputOption::VALUE_REQUIRED, 'Filter by command name substring')
            ->addOption('own-only', null, InputOption::VALUE_NONE, 'Hide core and third-party (vendor) commands, keep only own extensions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pattern = $input->getOption('pattern');
        $pattern = is_string($pattern) && '' !== $pattern ? $pattern : null;
        $ownOnly = (bool) $input->getOption('own-only');

        // filter() already excludes hidden commands and alias entries, so every
        // remaining name maps 1:1 to a distinct command — no dedup needed.
        $names = array_keys($this->commandRegistry->filter());
        sort($names);

        $commands = [];
        foreach ($names as $name) {
            if (null !== $pattern && !str_contains($name, $pattern)) {
                continue;
            }

            $described = $this->resolveCommand($name, $ownOnly);
            if (null !== $described) {
                $commands[] = $described;
            }
        }

        return $this->emit($output, ['commands' => $commands, 'commandCount' => count($commands)]);
    }

    /**
     * @return array{name: string, description: string, synopsis: string}|array{name: string, available: false, error: string}|null
     */
    private function resolveCommand(string $name, bool $ownOnly): ?array
    {
        try {
            $command = $this->commandRegistry->get($name);
        } catch (Throwable $exception) {
            // A third-party command's constructor can fail independently of this
            // listing; report it as unavailable instead of aborting discovery for
            // every other command. Ownership can't be determined without an
            // instance, so own-only mode drops it rather than risk showing vendor noise.
            return $ownOnly ? null : ['name' => $name, 'available' => false, 'error' => $exception->getMessage()];
        }

        if ($ownOnly && !$this->isOwnCommand($command)) {
            return null;
        }

        return $this->describe($command, $name);
    }
}
