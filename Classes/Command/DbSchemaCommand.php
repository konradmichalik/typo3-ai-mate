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

use Doctrine\DBAL\Schema\{Column, ForeignKeyConstraint, Index};
use Doctrine\DBAL\Schema\Exception\TableDoesNotExist;
use Doctrine\DBAL\Schema\Index\{IndexType, IndexedColumn};
use Doctrine\DBAL\Schema\Name\{OptionallyQualifiedName, UnqualifiedName};
use Doctrine\DBAL\Types\Type;
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

use function array_map;
use function array_slice;
use function array_values;
use function count;
use function sort;
use function sprintf;
use function str_contains;

/**
 * DbSchemaCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:db-schema:dump',
    description: 'Physical database schema: table list with a row-count estimate, or one table\'s columns, indexes and foreign keys, as JSON.',
)]
final class DbSchemaCommand extends AbstractJsonCommand
{
    private const MAX_TABLES = 300;

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
        parent::__construct();
    }

    /**
     * @return array{name: string, type: string, length: int|null, nullable: bool, default: mixed}
     */
    public function describeColumn(Column $column): array
    {
        return [
            'name' => self::unqualifiedName($column->getObjectName()),
            'type' => Type::lookupName($column->getType()),
            'length' => $column->getLength(),
            'nullable' => !$column->getNotnull(),
            'default' => $column->getDefault(),
        ];
    }

    /**
     * @return array{name: string, columns: list<string>, unique: bool}
     */
    public function describeIndex(Index $index): array
    {
        return [
            'name' => self::unqualifiedName($index->getObjectName()),
            'columns' => array_map(
                static fn (IndexedColumn $column): string => self::unqualifiedName($column->getColumnName()),
                $index->getIndexedColumns(),
            ),
            'unique' => IndexType::UNIQUE === $index->getType(),
        ];
    }

    /**
     * @return array{name: string|null, columns: list<string>, foreignTable: string, foreignColumns: list<string>}
     */
    public function describeForeignKey(ForeignKeyConstraint $foreignKey): array
    {
        $name = $foreignKey->getObjectName();

        return [
            'name' => null !== $name ? self::unqualifiedName($name) : null,
            'columns' => array_map(self::unqualifiedName(...), $foreignKey->getReferencingColumnNames()),
            'foreignTable' => $foreignKey->getReferencedTableName()->getUnqualifiedName()->getValue(),
            'foreignColumns' => array_map(self::unqualifiedName(...), $foreignKey->getReferencedColumnNames()),
        ];
    }

    protected function configure(): void
    {
        $this
            ->addArgument('table', InputArgument::OPTIONAL, 'Database table name, e.g. tt_content; omit to list all tables')
            ->addOption('pattern', null, InputOption::VALUE_REQUIRED, 'Filter the table list by name substring (only applies without a table argument)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = Cast::string($input->getArgument('table'));
        if ('' === $table) {
            $pattern = Cast::string($input->getOption('pattern'));

            return $this->emit($output, $this->listTables('' !== $pattern ? $pattern : null));
        }

        $description = $this->describeTable($table);
        if (null === $description) {
            return $this->emit($output, ['error' => sprintf('Unknown table "%s".', $table)], Command::FAILURE);
        }

        return $this->emit($output, $description);
    }

    /**
     * @return array{tables: list<array{name: string, rowCountEstimate: int}>, tableCount: int, _truncated: bool}
     */
    private function listTables(?string $pattern): array
    {
        $names = array_map(
            static fn (OptionallyQualifiedName $name): string => $name->getUnqualifiedName()->getValue(),
            $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME)->createSchemaManager()->introspectTableNames(),
        );
        sort($names);
        if (null !== $pattern) {
            $names = array_values(array_filter($names, static fn (string $name): bool => str_contains($name, $pattern)));
        }

        $tableCount = count($names);
        $truncated = $tableCount > self::MAX_TABLES;
        $names = $truncated ? array_slice($names, 0, self::MAX_TABLES) : $names;

        return [
            'tables' => array_map(fn (string $name): array => ['name' => $name, 'rowCountEstimate' => $this->rowCount($name)], $names),
            'tableCount' => $tableCount,
            '_truncated' => $truncated,
        ];
    }

    private function rowCount(string $table): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return Cast::int($queryBuilder->count('*')->from($table)->executeQuery()->fetchOne());
    }

    /**
     * @return array{table: string, columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>, foreignKeys: list<array<string, mixed>>}|null
     */
    private function describeTable(string $table): ?array
    {
        if ('' === $table) {
            return null;
        }

        // Only a genuinely missing table maps to "unknown table"; connection,
        // permission or driver failures must surface with their real cause.
        try {
            $introspected = $this->connectionPool->getConnectionForTable($table)->createSchemaManager()->introspectTableByUnquotedName($table);
        } catch (TableDoesNotExist) {
            return null;
        }

        return [
            'table' => $table,
            'columns' => array_map($this->describeColumn(...), $introspected->getColumns()),
            'indexes' => array_map($this->describeIndex(...), array_values($introspected->getIndexes())),
            'foreignKeys' => array_map($this->describeForeignKey(...), array_values($introspected->getForeignKeys())),
        ];
    }

    private static function unqualifiedName(UnqualifiedName $name): string
    {
        return $name->getIdentifier()->getValue();
    }
}
