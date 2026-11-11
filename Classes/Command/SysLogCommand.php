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

use InvalidArgumentException;
use KonradMichalik\Typo3AiMate\Command\Support\{RecordQueryInput, SysLogFormatter};
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

use function array_slice;
use function count;

/**
 * SysLogCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:syslog:search',
    description: 'Backend and DataHandler events from sys_log, deduplicated with counts, newest first, as JSON.',
)]
final class SysLogCommand extends AbstractJsonCommand
{
    private const TABLE = 'sys_log';
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    /**
     * Newest rows read for deduplication. sys_log grows without bound on a busy
     * installation, so older rows beyond this are counted but not grouped.
     */
    private const SCAN_LIMIT = 1000;

    /**
     * The only columns ever selected. userid, IP and workspace stay in the
     * database.
     */
    private const COLUMNS = ['tstamp', 'type', 'action', 'error', 'tablename', 'recuid', 'event_pid', 'details', 'log_data'];

    private readonly SysLogFormatter $formatter;

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
        parent::__construct();
        $this->formatter = new SysLogFormatter();
    }

    protected function configure(): void
    {
        $this
            ->addOption('error', null, InputOption::VALUE_NONE, 'Only entries flagged as an error (user error, system error, security notice, warning)')
            ->addOption('table', null, InputOption::VALUE_REQUIRED, 'Restrict to one table, e.g. tt_content')
            ->addOption('record-uid', null, InputOption::VALUE_REQUIRED, 'Restrict to one record uid')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'db|file|cache|extension|error|site|setting|login')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only entries at or after this time: relative (e.g. 30m, 2h, 7d) or any parseable date')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of distinct entries (capped at 200)', (string) self::DEFAULT_LIMIT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $typeOption = Cast::string($input->getOption('type'));
        $type = '' === $typeOption ? null : $this->formatter->typeId($typeOption);
        if ('' !== $typeOption && null === $type) {
            return $this->emit($output, ['error' => 'type must be one of db, file, cache, extension, error, site, setting, login.'], Command::FAILURE);
        }

        try {
            $recordUid = RecordQueryInput::intOption($input->getOption('record-uid'), 'record-uid');
        } catch (InvalidArgumentException $exception) {
            return $this->emit($output, ['error' => $exception->getMessage()], Command::FAILURE);
        }

        $filters = [
            'error' => true === $input->getOption('error'),
            'table' => Cast::string($input->getOption('table')),
            'recordUid' => $recordUid,
            'type' => $type,
            'since' => (new LogsCommand())->resolveSince($input->getOption('since')),
        ];
        $limit = min(self::MAX_LIMIT, max(1, Cast::int($input->getOption('limit'))));

        $total = $this->count($filters);
        $entries = $this->formatter->aggregate($this->newestRows($filters));

        $result = [
            'totalMatched' => $total,
            'distinct' => count($entries),
            'entries' => array_slice($entries, 0, $limit),
        ];
        if (count($entries) > $limit || $total > self::SCAN_LIMIT) {
            $result['_truncated'] = true;
        }

        return $this->emit($output, $result);
    }

    /**
     * @param array{error: bool, table: string, recordUid: int|null, type: int|null, since: int|null} $filters
     */
    private function count(array $filters): int
    {
        $queryBuilder = $this->filtered($filters);

        return Cast::int($queryBuilder->count('uid')->from(self::TABLE)->executeQuery()->fetchOne());
    }

    /**
     * @param array{error: bool, table: string, recordUid: int|null, type: int|null, since: int|null} $filters
     *
     * @return list<array<string, mixed>>
     */
    private function newestRows(array $filters): array
    {
        $queryBuilder = $this->filtered($filters);
        $queryBuilder->select(...self::COLUMNS)
            ->from(self::TABLE)
            ->orderBy('tstamp', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(self::SCAN_LIMIT);

        /** @var list<array<string, mixed>> $rows */
        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        return $rows;
    }

    /**
     * @param array{error: bool, table: string, recordUid: int|null, type: int|null, since: int|null} $filters
     */
    private function filtered(array $filters): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $expr = $queryBuilder->expr();

        if ($filters['error']) {
            $queryBuilder->andWhere($expr->gt('error', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
        }
        if ('' !== $filters['table']) {
            $queryBuilder->andWhere($expr->eq('tablename', $queryBuilder->createNamedParameter($filters['table'])));
        }
        if (null !== $filters['recordUid']) {
            $queryBuilder->andWhere($expr->eq('recuid', $queryBuilder->createNamedParameter($filters['recordUid'], Connection::PARAM_INT)));
        }
        if (null !== $filters['type']) {
            $queryBuilder->andWhere($expr->eq('type', $queryBuilder->createNamedParameter($filters['type'], Connection::PARAM_INT)));
        }
        if (null !== $filters['since']) {
            $queryBuilder->andWhere($expr->gte('tstamp', $queryBuilder->createNamedParameter($filters['since'], Connection::PARAM_INT)));
        }

        return $queryBuilder;
    }
}
