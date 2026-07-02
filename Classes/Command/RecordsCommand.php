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

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Exception\TableDoesNotExist;
use InvalidArgumentException;
use KonradMichalik\Typo3AiMate\Command\Support\{RecordQueryInput, RecordSchema, RecordTrimmer};
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};

use function array_map;
use function array_slice;
use function count;
use function sprintf;

/**
 * RecordsCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(
    name: 'typo3-ai-mate:records:query',
    description: 'Read-only record query for a database table (structured, parameterised) as JSON.',
)]
final class RecordsCommand extends AbstractJsonCommand
{
    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT = 100;
    private const VALUE_LIMIT = 200;

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('table', InputArgument::REQUIRED, 'Database table name, e.g. tt_content')
            ->addOption('uid', null, InputOption::VALUE_REQUIRED, 'Single record uid')
            ->addOption('pid', null, InputOption::VALUE_REQUIRED, 'Filter by parent page id')
            ->addOption('where', null, InputOption::VALUE_REQUIRED, 'Simple field=value pairs, comma-separated, AND-combined')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated explicit column selection')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows to return (capped at 100)', (string) self::DEFAULT_LIMIT)
            ->addOption('order-by', null, InputOption::VALUE_REQUIRED, 'field or field:desc')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'summary (compact, default) or full (all columns, untruncated)', 'summary')
            ->addOption('respect-enable-fields', null, InputOption::VALUE_NONE, 'Apply Deleted/Hidden/StartEnd restrictions (frontend view)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = Cast::string($input->getArgument('table'));
        $columns = $this->columns($table);
        if (null === $columns) {
            return $this->emit($output, ['error' => sprintf('Unknown table "%s".', $table)], Command::FAILURE);
        }

        $tcaTable = Cast::array(Cast::array($GLOBALS['TCA'] ?? null)[$table] ?? null);
        $ctrl = Cast::array($tcaTable['ctrl'] ?? null);

        try {
            $uid = RecordQueryInput::intOption($input->getOption('uid'), 'uid');
            $pid = RecordQueryInput::intOption($input->getOption('pid'), 'pid');
            $constraints = RecordQueryInput::parseWhere($input->getOption('where'), $columns);
            $requestedFields = RecordQueryInput::parseFields($input->getOption('fields'), $columns);
            $orderBy = RecordSchema::orderBy($input->getOption('order-by'), $columns, $ctrl);
        } catch (InvalidArgumentException $exception) {
            return $this->emit($output, ['error' => $exception->getMessage(), 'validColumns' => $columns], Command::FAILURE);
        }

        $enableColumns = RecordSchema::enableColumns($ctrl, $columns);
        $deleteField = RecordSchema::deleteField($ctrl, $columns);
        $sensitiveColumns = RecordSchema::sensitiveColumns(Cast::array($tcaTable['columns'] ?? null), $columns);

        $isFull = 'full' === strtolower(trim(Cast::string($input->getOption('format'))));
        $selectFields = $requestedFields
            ?? ($isFull ? $columns : RecordSchema::compactFields($ctrl, $columns, $enableColumns, $deleteField));
        $limit = min(self::MAX_LIMIT, max(1, Cast::int($input->getOption('limit'))));
        $respectEnableFields = true === $input->getOption('respect-enable-fields');

        $rows = $this->fetch($table, $selectFields, $uid, $pid, $constraints, $limit, $respectEnableFields, $orderBy);
        $limited = count($rows) > $limit;
        if ($limited) {
            $rows = array_slice($rows, 0, $limit);
        }

        return $this->emit($output, [
            'table' => $table,
            'count' => count($rows),
            'limited' => $limited,
            'restrictionsApplied' => $respectEnableFields,
            'fields' => $selectFields,
            'rows' => $this->shape($rows, $enableColumns, $deleteField, $sensitiveColumns, $isFull),
        ]);
    }

    /**
     * Real column names of the table, or null when the table does not exist.
     * The columns' object names are used (not the lowercased schema-manager array
     * keys) so mixed-case TYPO3 columns (CType, colPos) and reserved-word columns
     * (recursive) survive intact.
     *
     * @return list<string>|null
     */
    private function columns(string $table): ?array
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

        // getObjectName()->toString() would return a *quoted* identifier and the
        // QueryBuilder quotes again, so read the raw unquoted value instead.
        return array_map(
            static fn (Column $column): string => $column->getObjectName()->getIdentifier()->getValue(),
            $introspected->getColumns(),
        );
    }

    /**
     * @param list<string>                      $selectFields
     * @param list<array{0: string, 1: string}> $constraints
     * @param array{0: string|null, 1: string}  $orderBy
     *
     * @return list<array<string, mixed>>
     */
    private function fetch(string $table, array $selectFields, ?int $uid, ?int $pid, array $constraints, int $limit, bool $respectEnableFields, array $orderBy): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        if (!$respectEnableFields) {
            $queryBuilder->getRestrictions()->removeAll();
        }

        // Fetch one extra row to detect (and report) that the limit truncated the result.
        $queryBuilder->select(...$selectFields)->from($table)->setMaxResults($limit + 1);

        if (null !== $uid) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)));
        }
        if (null !== $pid) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)));
        }
        foreach ($constraints as [$field, $value]) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq($field, $queryBuilder->createNamedParameter($value)));
        }

        [$orderField, $orderDirection] = $orderBy;
        if (null !== $orderField) {
            $queryBuilder->orderBy($orderField, $orderDirection);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string>      $enableColumns
     * @param list<string>               $sensitiveColumns
     *
     * @return list<array<string, mixed>>
     */
    private function shape(array $rows, array $enableColumns, ?string $deleteField, array $sensitiveColumns, bool $isFull): array
    {
        $now = Cast::int(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Context\Context::class)->getPropertyFromAspect('date', 'timestamp') ?? time());
        $valueLimit = $isFull ? 0 : self::VALUE_LIMIT;

        return array_map(static function (array $row) use ($enableColumns, $deleteField, $sensitiveColumns, $now, $valueLimit): array {
            $flags = RecordTrimmer::flags($row, $enableColumns, $deleteField, $now);
            $row = RecordTrimmer::redact($row, $sensitiveColumns);

            return RecordTrimmer::truncateRow($row, $valueLimit) + ['_flags' => $flags];
        }, $rows);
    }
}
