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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command\Support;

use KonradMichalik\Typo3AiMate\Command\Support\TcaSchemaDescription;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Schema\ActiveRelation;
use TYPO3\CMS\Core\Schema\Field\{FieldCollection, FileFieldType, InputFieldType, TextFieldType};
use TYPO3\CMS\Core\Schema\{SchemaCollection, TcaSchema};

/**
 * TcaSchemaDescriptionTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class TcaSchemaDescriptionTest extends TestCase
{
    #[Test]
    public function describeFieldReportsLabelTypeRenderTypeEvalAndDisplayCond(): void
    {
        $field = new TextFieldType('bodytext', [
            'label' => 'Text',
            'renderType' => 'textTable',
            'eval' => 'trim',
            'displayCond' => 'FIELD:CType:=:textmedia',
        ]);

        self::assertSame(
            ['label' => 'Text', 'type' => 'text', 'renderType' => 'textTable', 'eval' => 'trim', 'displayCond' => 'FIELD:CType:=:textmedia'],
            TcaSchemaDescription::describeField($field),
        );
    }

    #[Test]
    public function describeFieldOmitsEmptyAndAbsentKeys(): void
    {
        $field = new InputFieldType('title', []);

        self::assertSame(['type' => 'input'], TcaSchemaDescription::describeField($field));
    }

    #[Test]
    public function describeCapabilitiesReadsSoftDeleteWorkspaceLanguageAndSorting(): void
    {
        $schema = new TcaSchema('tt_content', new FieldCollection([]), [
            'delete' => 'deleted',
            'versioningWS' => true,
            'languageField' => 'sys_language_uid',
            'transOrigPointerField' => 'l10n_parent',
            'sortby' => 'sorting',
        ]);

        self::assertSame(
            ['softDelete' => 'deleted', 'workspace' => true, 'language' => true, 'sorting' => 'sorting'],
            TcaSchemaDescription::describeCapabilities($schema),
        );
    }

    #[Test]
    public function describeCapabilitiesReturnsNullAndFalseWhenNotSupported(): void
    {
        $schema = new TcaSchema('static_table', new FieldCollection([]), []);

        self::assertSame(
            ['softDelete' => null, 'workspace' => false, 'language' => false, 'sorting' => null],
            TcaSchemaDescription::describeCapabilities($schema),
        );
    }

    #[Test]
    public function describeRecordTypesListsVisibleFieldsPerSubSchema(): void
    {
        $subSchema = new TcaSchema('tt_content.text', new FieldCollection([
            'bodytext' => new TextFieldType('bodytext', []),
        ]), []);
        $schema = new TcaSchema(
            'tt_content',
            new FieldCollection([]),
            ['type' => 'CType'],
            new SchemaCollection(['text' => $subSchema]),
        );

        self::assertSame(['text' => ['bodytext']], TcaSchemaDescription::describeRecordTypes($schema));
    }

    #[Test]
    public function describeRecordTypesReturnsEmptyWhenTableHasNoTypeField(): void
    {
        $schema = new TcaSchema('sys_category', new FieldCollection([]), []);

        self::assertSame([], TcaSchemaDescription::describeRecordTypes($schema));
    }

    #[Test]
    public function describeRelationsResolvesTheTargetTableAndRelationshipType(): void
    {
        $schema = new TcaSchema('tt_content', new FieldCollection([
            'image' => new FileFieldType('image', ['foreign_field' => 'uid_foreign'], [
                new ActiveRelation('sys_file_reference', null),
            ]),
            'header' => new InputFieldType('header', []),
        ]), []);

        self::assertSame(
            ['image' => ['type' => '1:n', 'toTables' => ['sys_file_reference']]],
            TcaSchemaDescription::describeRelations($schema),
        );
    }

    #[Test]
    public function describeRelationsDedupesMultipleRelationsToTheSameTable(): void
    {
        $schema = new TcaSchema('tt_content', new FieldCollection([
            'image' => new FileFieldType('image', [], [
                new ActiveRelation('sys_file_reference', null),
                new ActiveRelation('sys_file_reference', null),
            ]),
        ]), []);

        self::assertSame(['sys_file_reference'], TcaSchemaDescription::describeRelations($schema)['image']['toTables']);
    }

    #[Test]
    public function describeRelationsSkipsFieldsWithoutAnyResolvedRelation(): void
    {
        $schema = new TcaSchema('tt_content', new FieldCollection([
            'image' => new FileFieldType('image', [], []),
        ]), []);

        self::assertSame([], TcaSchemaDescription::describeRelations($schema));
    }
}
