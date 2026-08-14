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

use Doctrine\DBAL\Schema\{Column, ForeignKeyConstraint, Index};
use Doctrine\DBAL\Types\{Type, Types};
use KonradMichalik\Typo3AiMate\Command\DbSchemaCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * DbSchemaCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class DbSchemaCommandTest extends TestCase
{
    private DbSchemaCommand $command;

    protected function setUp(): void
    {
        $this->command = new DbSchemaCommand($this->createMock(ConnectionPool::class));
    }

    #[Test]
    public function describeColumnReportsTypeLengthNullabilityAndDefault(): void
    {
        $column = new Column('title', Type::getType(Types::STRING), [
            'length' => 255,
            'notnull' => false,
            'default' => 'Untitled',
        ]);

        self::assertSame(
            ['name' => 'title', 'type' => 'string', 'length' => 255, 'nullable' => true, 'default' => 'Untitled'],
            $this->command->describeColumn($column),
        );
    }

    #[Test]
    public function describeColumnReportsNotNullableWhenRequired(): void
    {
        $column = new Column('uid', Type::getType(Types::INTEGER), ['notnull' => true]);

        self::assertFalse($this->command->describeColumn($column)['nullable']);
    }

    #[Test]
    public function describeIndexReportsNameColumnsAndUniqueness(): void
    {
        $index = new Index('idx_parent', ['pid', 'sorting'], isUnique: true);

        self::assertSame(
            ['name' => 'idx_parent', 'columns' => ['pid', 'sorting'], 'unique' => true],
            $this->command->describeIndex($index),
        );
    }

    #[Test]
    public function describeIndexReportsNonUniqueIndexes(): void
    {
        $index = new Index('idx_pid', ['pid']);

        self::assertFalse($this->command->describeIndex($index)['unique']);
    }

    #[Test]
    public function describeForeignKeyReportsLocalAndReferencedColumns(): void
    {
        $foreignKey = new ForeignKeyConstraint(['parent_uid'], 'pages', ['uid'], 'fk_parent');

        self::assertSame(
            ['name' => 'fk_parent', 'columns' => ['parent_uid'], 'foreignTable' => 'pages', 'foreignColumns' => ['uid']],
            $this->command->describeForeignKey($foreignKey),
        );
    }
}
