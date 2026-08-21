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

use KonradMichalik\Typo3AiMate\Command\Support\TcaRecordTypes;
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Schema\ActiveRelation;
use TYPO3\CMS\Core\Schema\Capability\{SystemInternalFieldCapability, TcaSchemaCapability};
use TYPO3\CMS\Core\Schema\Field\{FieldTypeInterface, RelationalFieldTypeInterface};
use TYPO3\CMS\Core\Schema\{TcaSchema, TcaSchemaFactory};

use function array_key_exists;
use function count;
use function is_array;
use function is_string;
use function sprintf;

/**
 * TcaCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(
    name: 'typo3-ai-mate:tca:dump',
    description: 'Resolved TCA of a table (capabilities, record types, resolved relations, trimmed columns) or the list of all table names as JSON.',
)]
final class TcaCommand extends AbstractJsonCommand
{
    public function __construct(private readonly TcaSchemaFactory $tcaSchemaFactory)
    {
        parent::__construct();
    }

    /**
     * Reduce a full TCA table definition to the fields that matter for an
     * assistant reasoning about content modelling. Used both as the ctrl
     * output and, per field, as the fallback for a column the Schema API
     * does not build a {@see FieldTypeInterface} for.
     *
     * @param array<string, mixed> $definition
     *
     * @return array{ctrl: array<string, mixed>, columns: array<string, array<string, mixed>>}
     */
    public function extractTable(array $definition): array
    {
        /** @var array<string, mixed> $ctrl */
        $ctrl = is_array($definition['ctrl'] ?? null) ? $definition['ctrl'] : [];
        /** @var array<string, mixed> $columns */
        $columns = is_array($definition['columns'] ?? null) ? $definition['columns'] : [];

        $trimmedColumns = [];
        foreach ($columns as $field => $column) {
            if (!is_array($column)) {
                continue;
            }
            /** @var array<string, mixed> $config */
            $config = is_array($column['config'] ?? null) ? $column['config'] : [];

            $trimmedColumns[$field] = array_filter([
                'label' => $column['label'] ?? null,
                'type' => $config['type'] ?? null,
                'renderType' => $config['renderType'] ?? null,
                'foreign_table' => $config['foreign_table'] ?? null,
                'eval' => $config['eval'] ?? null,
                'displayCond' => $column['displayCond'] ?? null,
            ], static fn (mixed $value): bool => null !== $value);
        }

        return [
            'ctrl' => array_filter([
                'title' => $ctrl['title'] ?? null,
                'label' => $ctrl['label'] ?? null,
                'type' => $ctrl['type'] ?? null,
                'sortby' => $ctrl['sortby'] ?? null,
            ], static fn (mixed $value): bool => null !== $value),
            'columns' => $trimmedColumns,
        ];
    }

    /**
     * @return array{label?: string, type?: string, renderType?: string, foreign_table?: string, eval?: string, displayCond?: array<mixed>|string}
     */
    public function describeField(FieldTypeInterface $field): array
    {
        $config = $field->getConfiguration();
        $displayCond = $field->getDisplayConditions();

        return array_filter([
            'label' => $field->getLabel(),
            'type' => $field->getType(),
            'renderType' => Cast::string($config['renderType'] ?? null),
            'foreign_table' => Cast::string($config['foreign_table'] ?? null),
            'eval' => Cast::string($config['eval'] ?? null),
            'displayCond' => [] === $displayCond ? null : $displayCond,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }

    /**
     * Soft delete, workspace, language and manual-sorting support — the
     * capabilities most relevant to an assistant reasoning about content
     * modelling. {@see TcaSchemaCapability} defines many more (access
     * restrictions, copy behaviour, …) that are not surfaced here.
     *
     * @return array{softDelete: string|null, workspace: bool, language: bool, sorting: string|null}
     */
    public function describeCapabilities(TcaSchema $schema): array
    {
        return [
            'softDelete' => $this->capabilityFieldName($schema, TcaSchemaCapability::SoftDelete),
            'workspace' => $schema->isWorkspaceAware(),
            'language' => $schema->isLanguageAware(),
            'sorting' => $this->capabilityFieldName($schema, TcaSchemaCapability::SortByField),
        ];
    }

    /**
     * @return array<string, list<string>> record type value => field names visible on that type
     */
    public function describeRecordTypes(TcaSchema $schema): array
    {
        if (!$schema->supportsSubSchema()) {
            return [];
        }

        $recordTypes = [];
        foreach ($schema->getSubSchemata() as $typeValue => $subSchema) {
            if (!$subSchema instanceof TcaSchema) {
                continue;
            }
            // FieldCollection::getNames() is a v14-only addition (undefined on
            // TYPO3 v13.4) - iterate instead, which both versions support.
            $fieldNames = [];
            foreach ($subSchema->getFields() as $fieldName => $field) {
                $fieldNames[] = Cast::string($fieldName);
            }
            $recordTypes[Cast::string($typeValue)] = $fieldNames;
        }

        return $recordTypes;
    }

    /**
     * @return array<string, array{type: string, toTables: list<string>}> field name => resolved relation
     */
    public function describeRelations(TcaSchema $schema): array
    {
        $relations = [];
        foreach ($schema->getFields() as $fieldName => $field) {
            if (!$field instanceof RelationalFieldTypeInterface) {
                continue;
            }
            $toTables = array_values(array_unique(array_map(
                static fn (ActiveRelation $relation): string => $relation->toTable(),
                $field->getRelations(),
            )));
            if ([] === $toTables) {
                continue;
            }
            $relations[Cast::string($fieldName)] = ['type' => $field->getRelationshipType()->value, 'toTables' => $toTables];
        }

        return $relations;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('table', InputArgument::OPTIONAL, 'TCA table name, e.g. tt_content')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List all TCA table names instead of dumping a table')
            ->addOption('record-type', null, InputOption::VALUE_REQUIRED, 'Limit record types, columns and relations to one type value, e.g. textmedia')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated field names to limit columns and relations to');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = $input->getArgument('table');

        if (true === $input->getOption('list') || !is_string($table) || '' === $table) {
            $names = $this->tcaSchemaFactory->all()->getNames();
            sort($names);

            return $this->emit($output, $names);
        }

        if (!$this->tcaSchemaFactory->has($table)) {
            return $this->emit($output, ['error' => sprintf('Unknown TCA table "%s".', $table)], Command::FAILURE);
        }

        $schema = $this->tcaSchemaFactory->get($table);
        $recordTypes = $this->describeRecordTypes($schema);
        $recordType = $this->stringOption($input, 'record-type');

        if (null !== $recordType && !array_key_exists($recordType, $recordTypes)) {
            return $this->emit($output, $this->unknownRecordType($table, $recordType, array_keys($recordTypes)));
        }

        return $this->emit($output, $this->describeTable(
            $table,
            $schema,
            $recordTypes,
            $recordType,
            $this->fieldsOption($input),
        ));
    }

    /**
     * @param array<string, list<string>> $recordTypes
     * @param list<string>|null           $fields
     *
     * @return array<string, mixed>
     */
    private function describeTable(string $table, TcaSchema $schema, array $recordTypes, ?string $recordType, ?array $fields): array
    {
        /** @var array<string, mixed> $tca */
        $tca = is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        /** @var array<string, mixed> $tableDefinition */
        $tableDefinition = is_array($tca[$table] ?? null) ? $tca[$table] : [];
        $fallback = $this->extractTable($tableDefinition);

        $columns = [];
        foreach ($schema->getFields() as $fieldName => $field) {
            $columns[Cast::string($fieldName)] = $this->describeField($field);
        }
        // A field the Schema API does not build (rare, non-standard config)
        // falls back to the raw-TCA extraction instead of being dropped.
        $columns += $fallback['columns'];
        $relations = $this->describeRelations($schema);

        $scope = $fields ?? (null !== $recordType ? $recordTypes[$recordType] : null);
        if (null !== $recordType) {
            $recordTypes = [$recordType => $recordTypes[$recordType]];
        }

        $result = ['table' => $table];
        if (null !== $scope) {
            $unknown = null === $fields ? [] : array_values(array_diff($fields, array_keys($columns)));
            $keep = array_flip($scope);
            $columns = array_intersect_key($columns, $keep);
            $relations = array_intersect_key($relations, $keep);
            $recordTypes = TcaRecordTypes::limitToFields($recordTypes, $scope);

            $result['_hint'] = $this->scopeHint($table, $recordType, $fields, $unknown);
            if ([] !== $unknown) {
                $result['unknownFields'] = $unknown;
            }
        }

        return $result + [
            'ctrl' => $fallback['ctrl'],
            'capabilities' => $this->describeCapabilities($schema),
            'recordTypes' => [] === $recordTypes ? [] : TcaRecordTypes::collapse($recordTypes),
            'relations' => $relations,
            'columns' => $columns,
        ];
    }

    /**
     * @param list<string> $available
     *
     * @return array<string, mixed>
     */
    private function unknownRecordType(string $table, string $recordType, array $available): array
    {
        return [
            'table' => $table,
            'recordType' => $recordType,
            'recordTypeFound' => false,
            'availableRecordTypes' => $available,
            '_hint' => [] === $available
                ? sprintf('%s has no record types (no type field in ctrl), so it cannot be filtered by one. Call again without recordType.', $table)
                : sprintf('"%s" is not a record type of %s. availableRecordTypes lists the values it accepts.', $recordType, $table),
        ];
    }

    /**
     * @param list<string>|null $fields
     * @param list<string>      $unknown
     */
    private function scopeHint(string $table, ?string $recordType, ?array $fields, array $unknown): string
    {
        $hint = null !== $fields
            ? sprintf('columns and relations are limited to the %d requested field(s). Call without fields for the whole table.', count($fields))
            : sprintf('columns and relations are limited to the fields visible on recordType "%s". Call without recordType for the whole table.', (string) $recordType);

        return [] === $unknown
            ? $hint
            : $hint.sprintf(' unknownFields are not columns of %s.', $table);
    }

    /**
     * @return list<string>|null
     */
    private function fieldsOption(InputInterface $input): ?array
    {
        $value = $this->stringOption($input, 'fields');
        if (null === $value) {
            return null;
        }

        $fields = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $field): bool => '' !== $field));

        return [] === $fields ? null : $fields;
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = trim(Cast::string($input->getOption($name)));

        return '' !== $value ? $value : null;
    }

    private function capabilityFieldName(TcaSchema $schema, TcaSchemaCapability $capability): ?string
    {
        if (!$schema->hasCapability($capability)) {
            return null;
        }

        $value = $schema->getCapability($capability);

        return $value instanceof SystemInternalFieldCapability ? $value->getFieldName() : null;
    }
}
