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

use KonradMichalik\Typo3AiMate\Command\TsConfigCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * TsConfigCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TsConfigCommandTest extends TestCase
{
    #[Test]
    public function userTypeWithoutUserUidFailsWithAReadableError(): void
    {
        $tester = new CommandTester(new TsConfigCommand());
        $exitCode = $tester->execute(['pageId' => '1', '--type' => 'user']);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('--type=user requires a --user <uid>.', $result['error']);
    }

    #[Test]
    public function unknownTypeFailsWithAReadableError(): void
    {
        $tester = new CommandTester(new TsConfigCommand());
        $exitCode = $tester->execute(['pageId' => '1', '--type' => 'bogus']);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('Invalid --type "bogus"; expected "page" or "user".', $result['error']);
    }
}
