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

use JsonException;
use KonradMichalik\Typo3AiMate\Command\Support\{FlexFormDiff, RecordSchema, RecordTrimmer};
use KonradMichalik\Typo3AiMate\Support\{Cast, Redactor};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function count;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function sprintf;

/**
 * FlexFormCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:flexform:diff',
    description: 'Resolve a record\'s FlexForm data structure and diff it against the values actually stored, as JSON.',
)]
final class FlexFormCommand extends AbstractJsonCommand
{
    private const VALUE_LIMIT = 200;
    private const NESTED_VALUE = '[section or container content, not descended into]';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly FlexFormTools $flexFormTools,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
    ) {
        parent::__construct();
    }

    /**
     * Columns of a table whose TCA type is `flex`.
     *
     * @return list<string>
     */
    public function flexFields(string $table): array
    {
        $columns = Cast::array(Cast::array(Cast::array($GLOBALS['TCA'] ?? null)[$table] ?? null)['columns'] ?? null);

        $fields = [];
        foreach ($columns as $column => $definition) {
            if ('flex' === Cast::string(Cast::array(Cast::array($definition)['config'] ?? null)['type'] ?? null)) {
                $fields[] = Cast::string($column);
            }
        }

        return $fields;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('table', InputArgument::REQUIRED, 'Table of the record, e.g. tt_content')
            ->addArgument('uid', InputArgument::REQUIRED, 'Record uid')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'FlexForm column; omit when the table has exactly one');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = Cast::string($input->getArgument('table'));
        $uid = Cast::int($input->getArgument('uid'));

        if (RecordSchema::isBlockedTable($table)) {
            return $this->emit($output, ['error' => sprintf('Table "%s" is blocked: session storage is never exposed by typo3_ai_mate.', $table)], Command::FAILURE);
        }

        $flexFields = $this->flexFields($table);
        if ([] === $flexFields) {
            return $this->emit($output, [
                'table' => $table,
                'flexFields' => [],
                '_hint' => sprintf('%s has no column of TCA type "flex", so no record of it can carry a FlexForm.', $table),
            ]);
        }

        $field = $this->resolveField($input, $table, $flexFields);
        if (!is_string($field)) {
            return $this->emit($output, $field);
        }

        $row = $this->fetchRow($table, $uid);
        if (null === $row) {
            return $this->emit($output, [
                'table' => $table,
                'uid' => $uid,
                'recordFound' => false,
                '_hint' => sprintf('No row with uid %d in %s (deleted rows included).', $uid, $table),
            ]);
        }

        return $this->emit($output, $this->diff($table, $uid, $field, $row));
    }

    /**
     * @param list<string> $flexFields
     *
     * @return array<string, mixed>|string the resolved column, or the answer why it could not be resolved
     */
    private function resolveField(InputInterface $input, string $table, array $flexFields): array|string
    {
        $requested = trim(Cast::string($input->getOption('field')));
        if ('' === $requested) {
            return 1 === count($flexFields) ? $flexFields[0] : [
                'table' => $table,
                'flexFields' => $flexFields,
                '_hint' => sprintf('%s has %d FlexForm columns; name one with field=<column>.', $table, count($flexFields)),
            ];
        }

        return in_array($requested, $flexFields, true) ? $requested : [
            'table' => $table,
            'field' => $requested,
            'flexField' => false,
            'flexFields' => $flexFields,
            '_hint' => sprintf('"%s" is not a FlexForm column of %s. flexFields lists the ones that are.', $requested, $table),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function diff(string $table, int $uid, string $field, array $row): array
    {
        $stored = Cast::string($row[$field] ?? '');
        $answer = ['table' => $table, 'uid' => $uid, 'field' => $field, 'hasFlexForm' => '' !== $stored];
        if ('' === $stored) {
            return $answer + [
                '_hint' => sprintf('%s:%d stores no FlexForm value in %s. This record has no FlexForm — there is nothing to diff, which is the answer.', $table, $uid, $field),
            ];
        }

        $fieldTca = Cast::array(Cast::array(Cast::array(Cast::array($GLOBALS['TCA'] ?? null)[$table] ?? null)['columns'] ?? null)[$field] ?? null);
        // v14 refuses to resolve a default data structure without the schema; the
        // v13.4 signature has no such parameter and ignores the extra argument,
        // reading $GLOBALS['TCA'] itself. One call covers both.
        $schema = $this->tcaSchemaFactory->has($table) ? $this->tcaSchemaFactory->get($table) : null;

        try {
            $identifier = $this->flexFormTools->getDataStructureIdentifier($fieldTca, $table, $field, $row, $schema);
            $parsed = $this->flexFormTools->parseDataStructureByIdentifier($identifier, $schema);
        } catch (Throwable $exception) {
            return $answer + [
                'dataStructureResolved' => false,
                'error' => $exception->getMessage(),
                '_hint' => 'The record stores a FlexForm but its data structure could not be resolved, so stored values cannot be classified. The pointer field (e.g. CType or list_type) may reference a plugin that is no longer registered.',
            ];
        }

        $parsedXml = GeneralUtility::xml2array($stored);
        $diff = FlexFormDiff::compare(
            FlexFormDiff::dataStructureFields($parsed),
            is_array($parsedXml) ? FlexFormDiff::storedValues($parsedXml) : [],
        );

        $hint = 'orphaned values are stored on the record but no longer declared by the current data structure, so they are ignored at runtime — a renamed field looks exactly like this. missing fields are declared but not stored, so their default applies. Section and container contents are not descended into.';
        // Every stored value orphaned and nothing matched reads like wholesale
        // data loss, and hardly ever is one: that is what a record looks like
        // when it resolves to a structure other than the one being read, for
        // instance because a record type's columnsOverrides was overwritten
        // after the data structure was registered for it.
        if ([] === $diff['matched'] && [] !== $diff['orphaned']) {
            $hint .= sprintf(
                ' Not one stored value matches, which usually means this record resolves to a different data structure than the one you have in mind: it resolved by dataStructureKey "%s", so check that record type\'s columnsOverrides for %s before reading a file.',
                $this->dataStructureKey($identifier),
                $field,
            );
        }

        return $answer + [
            'dataStructureResolved' => true,
            'dataStructureIdentifier' => $identifier,
            'orphanedCount' => count($diff['orphaned']),
            'missingCount' => count($diff['missing']),
            'orphaned' => $this->presentValues($diff['orphaned']),
            'missing' => $diff['missing'],
            'matched' => $this->presentValues($diff['matched']),
            '_hint' => $hint,
        ];
    }

    /**
     * The record type the data structure was resolved by, read back out of the
     * identifier so the answer can name it without resolving it a second time.
     */
    private function dataStructureKey(string $identifier): string
    {
        try {
            $decoded = json_decode($identifier, true, 512, \JSON_THROW_ON_ERROR);
            // @codeCoverageIgnoreStart
        } catch (JsonException) {
            // Defensive: the identifier is what FlexFormTools just produced, so
            // invalid JSON here would be an upstream defect, not an input.
            return $identifier;
        }
        // @codeCoverageIgnoreEnd

        return is_array($decoded) ? Cast::string($decoded['dataStructureKey'] ?? '') : '';
    }

    /**
     * Stored values are user content: cap them and strip credentials, emails and
     * IPs the same way log output is treated. A section or container stores its
     * items as a nested array, which neither the cap nor the redaction reaches,
     * so its contents are named rather than emitted.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function presentValues(array $values): array
    {
        $presented = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $presented[$key] = self::NESTED_VALUE;

                continue;
            }

            $presented[$key] = is_string($value)
                ? Redactor::redact(RecordTrimmer::truncate($value, self::VALUE_LIMIT))
                : $value;
        }

        return $presented;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $table, int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        // The data structure identifier is derived from the row (ds_pointerField),
        // so the whole row is needed, and a deleted row is still worth diagnosing.
        $queryBuilder->getRestrictions()->removeAll();

        /** @var array<string, mixed>|false $row */
        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $row ? null : $row;
    }
}
