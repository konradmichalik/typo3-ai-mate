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

use KonradMichalik\Typo3AiMate\Command\Support\MateCliRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use TYPO3\CMS\Core\Core\Environment;

use function sprintf;
use function trim;

/**
 * InstallCommand.
 *
 * One-command onboarding: a thin wrapper around `mate init`/`mate discover`.
 * ai-mate v0.13 replaced the MCP server this command used to register in
 * `.mcp.json`/`opencode.json` with a plain CLI (`vendor/bin/mate tools:call
 * <name>`); `mate init`/`discover` now write the CLI-oriented agent
 * instructions themselves (a managed `CLAUDE.md`/`AGENTS.md` block), so there
 * is nothing project-specific left for this command to add — it exists only
 * so `composer require`/`composer update` has one command to point at.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:install',
    description: 'One-command onboarding: scaffold the Mate workspace and materialize agent instructions.',
)]
final class InstallCommand extends Command
{
    /**
     * @param MateCliRunner|null $mateRunnerOverride test-only seam; production
     *                                               always builds one from the resolved project root
     */
    public function __construct(private readonly ?MateCliRunner $mateRunnerOverride = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report every planned step without running anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (Environment::getContext()->isProduction()) {
            $io->error('typo3_ai_mate is disabled in the Production application context.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $projectRoot = rtrim(Environment::getProjectPath(), '/');

        $io->title('typo3-ai-mate: install');

        if (!$this->ensureMateWorkspace($io, $projectRoot, $dryRun)) {
            return Command::FAILURE;
        }

        $io->newLine();
        $io->success('typo3-ai-mate install complete.');
        $io->comment([
            'Next steps:',
            '  • "mate init" wrote agent instructions (a managed CLAUDE.md/AGENTS.md block) telling your assistant how to call the typo3-* tools via "vendor/bin/mate tools:call". Reload/restart your assistant so it picks them up.',
            '  • "mate init" also leaves bin/codex and bin/codex.bat in the project; they are launcher shims, not part of this extension.',
        ]);

        return Command::SUCCESS;
    }

    private function ensureMateWorkspace(SymfonyStyle $io, string $projectRoot, bool $dryRun): bool
    {
        if ($dryRun) {
            $io->text('[dry-run] Would run: vendor/bin/mate init --no-interaction');
            $io->text('[dry-run] Would run: vendor/bin/mate discover');

            return true;
        }

        $mateRunner = $this->mateRunnerOverride ?? new MateCliRunner($projectRoot);
        if (!$mateRunner->binaryExists()) {
            $io->error('vendor/bin/mate is missing. Run "composer install" first.');

            return false;
        }

        return $this->runMateStep($io, $mateRunner, 'init', ['--no-interaction'])
            && $this->runMateStep($io, $mateRunner, 'discover', []);
    }

    /**
     * @param list<string> $arguments
     */
    private function runMateStep(SymfonyStyle $io, MateCliRunner $mateRunner, string $mateCommand, array $arguments): bool
    {
        try {
            $process = $mateRunner->run($mateCommand, $arguments);
        } catch (ProcessTimedOutException) {
            $io->error(sprintf('vendor/bin/mate %s timed out.', $mateCommand));

            return false;
        }

        if (!$process->isSuccessful()) {
            $io->error(sprintf(
                'vendor/bin/mate %s failed (exit %s): %s',
                $mateCommand,
                (string) $process->getExitCode(),
                trim($process->getErrorOutput()) ?: trim($process->getOutput()),
            ));

            return false;
        }

        $io->text(sprintf('Ran: vendor/bin/mate %s', $mateCommand));

        return true;
    }
}
