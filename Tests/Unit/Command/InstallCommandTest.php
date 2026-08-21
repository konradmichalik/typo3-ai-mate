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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command;

use KonradMichalik\Ttt\Attribute\WithEnvironment;
use KonradMichalik\Typo3AiMate\Command\InstallCommand;
use KonradMichalik\Typo3AiMate\Command\Support\MateCliRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Core\Environment;

/**
 * InstallCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[WithEnvironment]
final class InstallCommandTest extends TestCase
{
    #[Test]
    public function installRunsMateWorkspaceStepsAndRegistersHostLaunchCommand(): void
    {
        $this->fakeMateBinary();
        $tester = new CommandTester(new InstallCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Ran: vendor/bin/mate init', $tester->getDisplay());
        self::assertStringContainsString('Ran: vendor/bin/mate discover', $tester->getDisplay());
        self::assertStringContainsString('created "mcpServers.typo3-ai-mate"', $tester->getDisplay());
        self::assertStringNotContainsString('would create', $tester->getDisplay());
        self::assertSame(['command' => './vendor/bin/mate', 'args' => ['serve']], $this->registeredMcpServer());
    }

    #[Test]
    public function installRegistersDdevLaunchCommandForDdevProjects(): void
    {
        $this->fakeMateBinary();
        mkdir(Environment::getProjectPath().'/.ddev', 0o700, true);
        file_put_contents(Environment::getProjectPath().'/.ddev/config.yaml', "name: example\n");

        $tester = new CommandTester(new InstallCommand());
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['command' => 'ddev', 'args' => ['exec', 'vendor/bin/mate', 'serve']], $this->registeredMcpServer());
    }

    #[Test]
    public function installUpdatesAStaleMcpJsonEntryWithoutTheDryRunTense(): void
    {
        $this->fakeMateBinary();
        file_put_contents($this->mcpJsonPath(), json_encode([
            'mcpServers' => ['typo3-ai-mate' => ['command' => 'stale', 'args' => []]],
        ]));

        $tester = new CommandTester(new InstallCommand());
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('updated "mcpServers.typo3-ai-mate"', $tester->getDisplay());
        self::assertStringNotContainsString('would update', $tester->getDisplay());
    }

    #[Test]
    public function aTimedOutMateStepReportsAClearErrorInsteadOfCrashing(): void
    {
        $this->fakeMateBinary('<?php usleep(200_000);');
        $tester = new CommandTester(new InstallCommand(new MateCliRunner(Environment::getProjectPath(), 0.05)));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('vendor/bin/mate init timed out.', $tester->getDisplay());
        self::assertFileDoesNotExist($this->mcpJsonPath());
    }

    #[Test]
    public function installIsIdempotent(): void
    {
        $this->fakeMateBinary();
        $tester = new CommandTester(new InstallCommand());
        $tester->execute([]);
        $firstMcpJson = file_get_contents($this->mcpJsonPath());

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame($firstMcpJson, file_get_contents($this->mcpJsonPath()));
        self::assertStringContainsString('already up to date', $tester->getDisplay());
    }

    #[Test]
    public function dryRunPerformsNoWrites(): void
    {
        $tester = new CommandTester(new InstallCommand());

        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('[dry-run] Would run: vendor/bin/mate init', $tester->getDisplay());
        self::assertStringContainsString('would create "mcpServers.typo3-ai-mate"', $tester->getDisplay());
        self::assertFileDoesNotExist($this->mcpJsonPath());
        self::assertFileDoesNotExist(Environment::getProjectPath().'/vendor/bin/mate');
    }

    #[Test]
    public function skipMcpJsonLeavesTheFileUntouched(): void
    {
        $this->fakeMateBinary();
        $tester = new CommandTester(new InstallCommand());

        $exitCode = $tester->execute(['--skip-mcp-json' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileDoesNotExist($this->mcpJsonPath());
    }

    #[Test]
    public function missingMateBinaryFailsWithAClearMessage(): void
    {
        $tester = new CommandTester(new InstallCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('vendor/bin/mate is missing', $tester->getDisplay());
        self::assertFileDoesNotExist($this->mcpJsonPath());
    }

    #[Test]
    public function aFailingMateStepAbortsBeforeRegisteringTheMcpServer(): void
    {
        $this->fakeMateBinary('<?php fwrite(STDERR, "boom"); exit(1);');
        $tester = new CommandTester(new InstallCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('vendor/bin/mate init failed', $tester->getDisplay());
        self::assertStringContainsString('boom', $tester->getDisplay());
        self::assertFileDoesNotExist($this->mcpJsonPath());
    }

    #[Test]
    #[WithEnvironment(context: 'Production')]
    public function installIsDisabledInProduction(): void
    {
        $tester = new CommandTester(new InstallCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Production application context', $tester->getDisplay());
    }

    #[Test]
    public function withoutAnyMarkerBothHarnessesAreRegistered(): void
    {
        $this->fakeMateBinary();
        $tester = new CommandTester(new InstallCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Registering the MCP server for: claude, opencode.', $tester->getDisplay());
        self::assertStringNotContainsString('Not registered for:', $tester->getDisplay());
        self::assertFileExists($this->mcpJsonPath());
        self::assertFileExists($this->opencodeJsonPath());
    }

    #[Test]
    public function aDetectedHarnessIsTheOnlyOneRegistered(): void
    {
        $this->fakeMateBinary();
        touch(Environment::getProjectPath().'/CLAUDE.md');
        $tester = new CommandTester(new InstallCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Registering the MCP server for: claude.', $tester->getDisplay());
        self::assertStringContainsString('Not registered for: opencode.', $tester->getDisplay());
        self::assertFileExists($this->mcpJsonPath());
        self::assertFileDoesNotExist($this->opencodeJsonPath());
    }

    #[Test]
    public function anExplicitAgentRegistersInThatHarnessOwnShape(): void
    {
        $this->fakeMateBinary();
        $tester = new CommandTester(new InstallCommand());

        $exitCode = $tester->execute(['--agent' => 'opencode']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertFileDoesNotExist($this->mcpJsonPath());
        self::assertStringContainsString('created "mcp.typo3-ai-mate"', $tester->getDisplay());

        $decoded = json_decode((string) file_get_contents($this->opencodeJsonPath()), true);
        self::assertIsArray($decoded);
        self::assertSame(
            ['type' => 'local', 'command' => ['./vendor/bin/mate', 'serve'], 'enabled' => true],
            ((array) $decoded['mcp'])['typo3-ai-mate'],
        );
    }

    #[Test]
    public function anUnknownAgentFailsAndNamesTheAcceptedValues(): void
    {
        $tester = new CommandTester(new InstallCommand());

        $exitCode = $tester->execute(['--agent' => 'emacs']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Unknown --agent "emacs"', $tester->getDisplay());
        self::assertStringContainsString('claude, opencode, all', $tester->getDisplay());
    }

    #[Test]
    public function anEmptyObjectElsewhereInTheFileSurvivesTheMerge(): void
    {
        $this->fakeMateBinary();
        // opencode refuses to start when a map it expects serialises as [], which
        // is what a decode-as-array round trip does to an empty JSON object.
        file_put_contents($this->opencodeJsonPath(), '{"agent": {}, "mcp": {}}');

        $tester = new CommandTester(new InstallCommand());
        $exitCode = $tester->execute(['--agent' => 'opencode']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('"agent": {}', (string) file_get_contents($this->opencodeJsonPath()));
    }

    private function fakeMateBinary(string $body = '<?php exit(0);'): void
    {
        $binDir = Environment::getProjectPath().'/vendor/bin';
        if (!is_dir($binDir)) {
            mkdir($binDir, 0o700, true);
        }
        file_put_contents($binDir.'/mate', $body);
    }

    private function mcpJsonPath(): string
    {
        return Environment::getProjectPath().'/.mcp.json';
    }

    private function opencodeJsonPath(): string
    {
        return Environment::getProjectPath().'/opencode.json';
    }

    /**
     * @return array<array-key, mixed>
     */
    private function registeredMcpServer(): array
    {
        $decoded = json_decode((string) file_get_contents($this->mcpJsonPath()), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['mcpServers']);
        self::assertIsArray($decoded['mcpServers']['typo3-ai-mate']);

        return $decoded['mcpServers']['typo3-ai-mate'];
    }
}
