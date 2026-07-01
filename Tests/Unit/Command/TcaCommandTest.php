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

use KonradMichalik\Typo3AiMate\Command\TcaCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * TcaCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TcaCommandTest extends TestCase
{
    private mixed $originalTca = null;

    protected function setUp(): void
    {
        $this->originalTca = $GLOBALS['TCA'] ?? null;
    }

    protected function tearDown(): void
    {
        if (null === $this->originalTca) {
            unset($GLOBALS['TCA']);
        } else {
            $GLOBALS['TCA'] = $this->originalTca;
        }
    }

    #[Test]
    public function extractTableKeepsOnlyTheRelevantCtrlKeys(): void
    {
        $result = (new TcaCommand())->extractTable([
            'ctrl' => [
                'title' => 'Content',
                'label' => 'header',
                'type' => 'CType',
                'sortby' => 'sorting',
                'crdate' => 'crdate',
                'tstamp' => 'tstamp',
            ],
            'columns' => [],
        ]);

        self::assertSame(
            ['title' => 'Content', 'label' => 'header', 'type' => 'CType', 'sortby' => 'sorting'],
            $result['ctrl'],
        );
    }

    #[Test]
    public function extractTableTrimsColumnsAndDropsNullValues(): void
    {
        $result = (new TcaCommand())->extractTable([
            'ctrl' => [],
            'columns' => [
                'header' => [
                    'label' => 'Header',
                    'config' => ['type' => 'input', 'eval' => 'trim', 'size' => 30],
                ],
                'image' => [
                    'config' => ['type' => 'file', 'foreign_table' => 'sys_file'],
                ],
                'bodytext' => [
                    'label' => 'Text',
                    'config' => ['type' => 'text', 'renderType' => 'textTable'],
                    'displayCond' => 'FIELD:CType:=:textmedia',
                ],
            ],
        ]);

        self::assertSame(['label' => 'Header', 'type' => 'input', 'eval' => 'trim'], $result['columns']['header']);
        self::assertSame(['type' => 'file', 'foreign_table' => 'sys_file'], $result['columns']['image']);
        self::assertSame(
            ['label' => 'Text', 'type' => 'text', 'renderType' => 'textTable', 'displayCond' => 'FIELD:CType:=:textmedia'],
            $result['columns']['bodytext'],
        );
    }

    #[Test]
    public function extractTableToleratesMissingCtrlAndColumns(): void
    {
        $result = (new TcaCommand())->extractTable([]);

        self::assertSame(['ctrl' => [], 'columns' => []], $result);
    }

    #[Test]
    public function extractTableSkipsColumnsThatAreNotArrays(): void
    {
        $result = (new TcaCommand())->extractTable([
            'columns' => [
                'broken' => 'not-an-array',
                'header' => ['config' => ['type' => 'input']],
            ],
        ]);

        self::assertArrayNotHasKey('broken', $result['columns']);
        self::assertArrayHasKey('header', $result['columns']);
    }

    #[Test]
    public function executeListsAllTableNamesSortedWhenNoTableGiven(): void
    {
        $GLOBALS['TCA'] = ['tt_content' => [], 'pages' => [], 'be_users' => []];

        $tester = new CommandTester(new TcaCommand());
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertSame(['be_users', 'pages', 'tt_content'], json_decode($tester->getDisplay(), true));
    }

    #[Test]
    public function executeDumpsTheTrimmedTableDefinition(): void
    {
        $GLOBALS['TCA'] = ['pages' => ['ctrl' => ['title' => 'Pages'], 'columns' => []]];

        $tester = new CommandTester(new TcaCommand());
        $exitCode = $tester->execute(['table' => 'pages']);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame(['title' => 'Pages'], $result['ctrl']);
    }

    #[Test]
    public function executeFailsForAnUnknownTable(): void
    {
        $GLOBALS['TCA'] = ['pages' => []];

        $tester = new CommandTester(new TcaCommand());
        $exitCode = $tester->execute(['table' => 'does_not_exist']);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertArrayHasKey('error', $result);
    }
}
