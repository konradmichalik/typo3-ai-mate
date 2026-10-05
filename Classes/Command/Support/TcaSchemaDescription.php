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

namespace KonradMichalik\Typo3AiMate\Command\Support;

use KonradMichalik\Typo3AiMate\Support\Cast;
use TYPO3\CMS\Core\Schema\{ActiveRelation, TcaSchema};
use TYPO3\CMS\Core\Schema\Capability\{SystemInternalFieldCapability, TcaSchemaCapability};
use TYPO3\CMS\Core\Schema\Field\{FieldTypeInterface, RelationalFieldTypeInterface};

/**
 * TcaSchemaDescription.
 *
 * What the Schema API resolved for a table: fields, capabilities, record
 * types and relations.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class TcaSchemaDescription
{
    /**
     * @return array{label?: string, type?: string, renderType?: string, foreign_table?: string, eval?: string, displayCond?: array<mixed>|string}
     */
    public static function describeField(FieldTypeInterface $field): array
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
    public static function describeCapabilities(TcaSchema $schema): array
    {
        return [
            'softDelete' => self::capabilityFieldName($schema, TcaSchemaCapability::SoftDelete),
            'workspace' => $schema->isWorkspaceAware(),
            'language' => $schema->isLanguageAware(),
            'sorting' => self::capabilityFieldName($schema, TcaSchemaCapability::SortByField),
        ];
    }

    /**
     * @return array<string, list<string>> record type value => field names visible on that type
     */
    public static function describeRecordTypes(TcaSchema $schema): array
    {
        if (!$schema->supportsSubSchema()) {
            return [];
        }

        $recordTypes = [];
        foreach ($schema->getSubSchemata() as $typeValue => $subSchema) {
            // Defensive: the core's sub-schemata are TcaSchema instances; the
            // guard exists because getSubSchemata() is typed loosely.
            // @codeCoverageIgnoreStart
            if (!$subSchema instanceof TcaSchema) {
                continue;
            }
            // @codeCoverageIgnoreEnd
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
    public static function describeRelations(TcaSchema $schema): array
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

    private static function capabilityFieldName(TcaSchema $schema, TcaSchemaCapability $capability): ?string
    {
        if (!$schema->hasCapability($capability)) {
            return null;
        }

        $value = $schema->getCapability($capability);

        return $value instanceof SystemInternalFieldCapability ? $value->getFieldName() : null;
    }
}
