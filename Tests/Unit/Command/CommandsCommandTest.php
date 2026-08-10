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
use KonradMichalik\Typo3AiMate\Command\CommandsCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\{Command, HelpCommand};
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;

/**
 * CommandsCommandTest.
 *
 * isOwnCommand()/execute() classify commands by their file path relative to
 * Environment::getProjectPath(), so the real project root (not a temporary
 * sandbox) must be in effect for "own" vs. "vendor" to resolve correctly
 * against the actual Classes/ and vendor/ directories on disk.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[WithEnvironment(projectPath: 'self')]
final class CommandsCommandTest extends TestCase
{
    #[Test]
    public function describeReturnsNameDescriptionAndSynopsis(): void
    {
        $command = new CommandsCommand($this->createMock(CommandRegistry::class));
        $target = new Command('sample:command');
        $target->setDescription('Sample description');

        $described = $command->describe($target, 'sample:command');

        self::assertSame('sample:command', $described['name']);
        self::assertSame('Sample description', $described['description']);
        self::assertSame('sample:command', $described['synopsis']);
    }

    #[Test]
    public function isOwnCommandIsTrueForAClassOutsideVendor(): void
    {
        $command = new CommandsCommand($this->createMock(CommandRegistry::class));

        self::assertTrue($command->isOwnCommand($command));
    }

    #[Test]
    public function isOwnCommandIsFalseForAVendorClass(): void
    {
        $command = new CommandsCommand($this->createMock(CommandRegistry::class));

        self::assertFalse($command->isOwnCommand(new HelpCommand()));
    }

    #[Test]
    public function executeListsCommandsSortedByName(): void
    {
        $registry = $this->createMock(CommandRegistry::class);
        $registry->method('filter')->willReturn(['help' => [], 'typo3-ai-mate:commands:list' => []]);
        $registry->method('get')->willReturnMap([
            ['help', new HelpCommand()],
            ['typo3-ai-mate:commands:list', new CommandsCommand($registry)],
        ]);

        $tester = new CommandTester(new CommandsCommand($registry));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        $commands = $result['commands'];
        self::assertIsArray($commands);
        self::assertSame(['help', 'typo3-ai-mate:commands:list'], array_column($commands, 'name'));
        self::assertSame(2, $result['commandCount']);
    }

    #[Test]
    public function executeFiltersCommandsByNameSubstring(): void
    {
        $registry = $this->createMock(CommandRegistry::class);
        $registry->method('filter')->willReturn(['foo:bar' => [], 'baz:qux' => []]);
        $registry->method('get')->willReturn(new HelpCommand());

        $tester = new CommandTester(new CommandsCommand($registry));
        $tester->execute(['--pattern' => 'foo']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        $commands = $result['commands'];
        self::assertIsArray($commands);
        self::assertSame(['foo:bar'], array_column($commands, 'name'));
    }

    #[Test]
    public function executeHidesVendorCommandsWhenOwnOnlyRequested(): void
    {
        $registry = $this->createMock(CommandRegistry::class);
        $registry->method('filter')->willReturn(['help' => [], 'typo3-ai-mate:commands:list' => []]);
        $registry->method('get')->willReturnMap([
            ['help', new HelpCommand()],
            ['typo3-ai-mate:commands:list', new CommandsCommand($registry)],
        ]);

        $tester = new CommandTester(new CommandsCommand($registry));
        $tester->execute(['--own-only' => true]);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        $commands = $result['commands'];
        self::assertIsArray($commands);
        self::assertSame(['typo3-ai-mate:commands:list'], array_column($commands, 'name'));
    }
}
