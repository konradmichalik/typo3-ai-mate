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
    public function installRunsTheMateWorkspaceSteps(): void
    {
        $this->fakeMateBinary();
        $tester = new CommandTester(new InstallCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Ran: vendor/bin/mate init', $tester->getDisplay());
        self::assertStringContainsString('Ran: vendor/bin/mate discover', $tester->getDisplay());
        self::assertStringContainsString('install complete', $tester->getDisplay());
    }

    #[Test]
    public function aTimedOutMateStepReportsAClearErrorInsteadOfCrashing(): void
    {
        $this->fakeMateBinary('<?php usleep(200_000);');
        $tester = new CommandTester(new InstallCommand(new MateCliRunner(Environment::getProjectPath(), 0.05)));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('vendor/bin/mate init timed out.', $tester->getDisplay());
    }

    #[Test]
    public function dryRunPerformsNoWrites(): void
    {
        $tester = new CommandTester(new InstallCommand());

        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('[dry-run] Would run: vendor/bin/mate init', $tester->getDisplay());
        self::assertStringContainsString('[dry-run] Would run: vendor/bin/mate discover', $tester->getDisplay());
        self::assertFileDoesNotExist(Environment::getProjectPath().'/vendor/bin/mate');
    }

    #[Test]
    public function missingMateBinaryFailsWithAClearMessage(): void
    {
        $tester = new CommandTester(new InstallCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('vendor/bin/mate is missing', $tester->getDisplay());
    }

    #[Test]
    public function aFailingMateStepReportsTheError(): void
    {
        $this->fakeMateBinary('<?php fwrite(STDERR, "boom"); exit(1);');
        $tester = new CommandTester(new InstallCommand());

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('vendor/bin/mate init failed', $tester->getDisplay());
        self::assertStringContainsString('boom', $tester->getDisplay());
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

    private function fakeMateBinary(string $body = '<?php exit(0);'): void
    {
        $binDir = Environment::getProjectPath().'/vendor/bin';
        if (!is_dir($binDir)) {
            mkdir($binDir, 0o700, true);
        }
        file_put_contents($binDir.'/mate', $body);
    }
}
