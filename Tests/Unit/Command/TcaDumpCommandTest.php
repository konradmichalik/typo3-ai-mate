<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <km@move-elevator.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command;

use KonradMichalik\Typo3AiMate\Command\TcaDumpCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TcaDumpCommandTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final class TcaDumpCommandTest extends TestCase
{
    #[Test]
    public function extractTableKeepsOnlyTheRelevantCtrlKeys(): void
    {
        $result = (new TcaDumpCommand())->extractTable([
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
        $result = (new TcaDumpCommand())->extractTable([
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
        $result = (new TcaDumpCommand())->extractTable([]);

        self::assertSame(['ctrl' => [], 'columns' => []], $result);
    }
}
