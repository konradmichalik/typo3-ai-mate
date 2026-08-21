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

use KonradMichalik\Typo3AiMate\Command\Support\{AgentHarness, DdevEnvironment, MateCliRunner, McpJsonRegistrar};
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use TYPO3\CMS\Core\Core\Environment;

use function implode;
use function in_array;
use function sprintf;

/**
 * InstallCommand.
 *
 * One-command onboarding: orchestrates the Mate workspace setup (`mate init`,
 * `mate discover`) and registers this project's MCP server, adding only the
 * piece Mate cannot know about — how an MCP client on the host launches the
 * server for this particular project layout (plain PHP process vs. `ddev
 * exec`). Does not reimplement `mate discover`'s instruction materialization
 * (`mate/AGENT_INSTRUCTIONS.md`, the `AGENTS.md` managed block).
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:install',
    description: 'One-command onboarding: scaffold the Mate workspace and register the MCP server for this project.',
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
        $this->addOption('skip-mcp-json', null, InputOption::VALUE_NONE, 'Do not write or update any MCP server registration.');
        $this->addOption('agent', null, InputOption::VALUE_REQUIRED, 'Which assistant to register for: claude|opencode|all. Default: autodetect from the project, "all" when nothing is recognisable.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report every planned step without writing or executing anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (Environment::getContext()->isProduction()) {
            $io->error('typo3_ai_mate is disabled in the Production application context.');

            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $skipMcpJson = (bool) $input->getOption('skip-mcp-json');
        $projectRoot = rtrim(Environment::getProjectPath(), '/');
        $ddev = DdevEnvironment::detect($projectRoot);

        $harnesses = $this->resolveHarnesses($input, $projectRoot);
        if (null === $harnesses) {
            $io->error(sprintf(
                'Unknown --agent "%s". Expected one of: %s, all.',
                Cast::string($input->getOption('agent')),
                implode(', ', array_column(AgentHarness::cases(), 'value')),
            ));

            return Command::FAILURE;
        }

        $io->title('typo3-ai-mate: install');
        $io->text(sprintf(
            'DDEV project: %s%s',
            $ddev->isDdevProject ? 'yes' : 'no',
            $ddev->isInsideContainer ? ' — running inside the DDEV web container; the registered command below still uses the host-side "ddev exec" form, since your assistant runs on the host.' : '',
        ));
        $io->newLine();

        if (!$this->ensureMateWorkspace($io, $projectRoot, $dryRun)) {
            return Command::FAILURE;
        }

        $io->newLine();

        if ($skipMcpJson) {
            $io->text('Skipped: MCP server registration (--skip-mcp-json).');
        } elseif (!$this->registerMcpServer($io, $projectRoot, $ddev, $harnesses, $dryRun)) {
            return Command::FAILURE;
        }

        $io->newLine();
        $io->success('typo3-ai-mate install complete.');
        $io->comment([
            'Next steps:',
            '  • Reconnect your assistant so it picks up the registered MCP server.',
            '  • In Claude Code: run "/mcp" and reconnect "typo3-ai-mate".',
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

    /**
     * The harnesses to register for: the explicit --agent, otherwise whatever the
     * project shows evidence of, otherwise all of them. Null signals an unknown
     * --agent value.
     *
     * @return list<AgentHarness>|null
     */
    private function resolveHarnesses(InputInterface $input, string $projectRoot): ?array
    {
        $requested = strtolower(trim(Cast::string($input->getOption('agent'))));
        if ('all' === $requested) {
            return AgentHarness::cases();
        }
        if ('' !== $requested) {
            $harness = AgentHarness::tryFrom($requested);

            return null === $harness ? null : [$harness];
        }

        $detected = AgentHarness::detect($projectRoot);

        // Nothing recognisable means we cannot tell, not that nobody is there: a
        // partial provision (instructions but no server) is worse than either
        // extreme.
        return [] === $detected ? AgentHarness::cases() : $detected;
    }

    /**
     * @param list<AgentHarness> $harnesses
     */
    private function registerMcpServer(SymfonyStyle $io, string $projectRoot, DdevEnvironment $ddev, array $harnesses, bool $dryRun): bool
    {
        $argv = $ddev->mcpServerLaunchArgv();
        $io->text(sprintf('Registering the MCP server for: %s.', implode(', ', array_column($harnesses, 'value'))));

        // Name the harnesses left out, so a half-install is stated rather than
        // discovered when the assistant cannot call a single tool.
        $skipped = array_values(array_filter(AgentHarness::cases(), static fn (AgentHarness $harness): bool => !in_array($harness, $harnesses, true)));
        if ([] !== $skipped) {
            $io->text(sprintf(
                'Not registered for: %s. Pass --agent=all (or the name) if you drive this project with it.',
                implode(', ', array_column($skipped, 'value')),
            ));
        }

        foreach ($harnesses as $harness) {
            $registrar = new McpJsonRegistrar($projectRoot.'/'.$harness->configFile(), $harness->sectionKey());
            $result = $registrar->register($harness->serverEntry($argv), $dryRun);

            if (isset($result['error'])) {
                $io->error($result['error']);

                return false;
            }

            $io->text(sprintf(
                '%s%s: %s "%s.typo3-ai-mate" (%s).',
                $dryRun ? '[dry-run] ' : '',
                $harness->configFile(),
                match ($result['action']) {
                    'created' => $dryRun ? 'would create' : 'created',
                    'updated' => $dryRun ? 'would update' : 'updated',
                    'unchanged' => 'already up to date',
                },
                $harness->sectionKey(),
                $harness->value,
            ));
        }

        return true;
    }
}
