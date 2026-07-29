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

use KonradMichalik\Typo3AiMate\Command\AbstractJsonCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{ArrayInput, InputInterface};
use Symfony\Component\Console\Output\{BufferedOutput, OutputInterface};
use TYPO3\CMS\Core\Core\{ApplicationContext, Environment};

/**
 * AbstractJsonCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class AbstractJsonCommandTest extends TestCase
{
    #[Test]
    public function runReturnsAnErrorEnvelopeInProduction(): void
    {
        $this->initializeEnvironment('Production');
        $command = $this->command();
        $output = new BufferedOutput();

        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(Command::FAILURE, $exitCode);
        $payload = json_decode(trim($output->fetch()), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('error', $payload);
        self::assertIsString($payload['error']);
        self::assertStringContainsString('Production', $payload['error']);
    }

    #[Test]
    public function runDelegatesToExecuteInDevelopment(): void
    {
        $this->initializeEnvironment('Development');
        $command = $this->command();
        $output = new BufferedOutput();

        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['executed' => true], json_decode(trim($output->fetch()), true));
    }

    #[Test]
    public function runDelegatesToExecuteInTesting(): void
    {
        $this->initializeEnvironment('Testing');
        $command = $this->command();
        $output = new BufferedOutput();

        $exitCode = $command->run(new ArrayInput([]), $output);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['executed' => true], json_decode(trim($output->fetch()), true));
    }

    private function command(): AbstractJsonCommand
    {
        return new class extends AbstractJsonCommand {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return $this->emit($output, ['executed' => true]);
            }
        };
    }

    private function initializeEnvironment(string $context): void
    {
        $base = sys_get_temp_dir();
        Environment::initialize(
            new ApplicationContext($context),
            true,
            false,
            $base,
            $base,
            $base.'/var',
            $base.'/config',
            '',
            'UNIX',
        );
    }
}
