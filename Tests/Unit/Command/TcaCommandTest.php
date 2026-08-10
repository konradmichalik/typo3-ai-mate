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
use TYPO3\CMS\Core\Schema\ActiveRelation;
use TYPO3\CMS\Core\Schema\Field\{FieldCollection, FileFieldType, InputFieldType, TextFieldType};
use TYPO3\CMS\Core\Schema\{SchemaCollection, TcaSchema, TcaSchemaFactory};

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
        $result = $this->command()->extractTable([
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
        $result = $this->command()->extractTable([
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
        $result = $this->command()->extractTable([]);

        self::assertSame(['ctrl' => [], 'columns' => []], $result);
    }

    #[Test]
    public function extractTableSkipsColumnsThatAreNotArrays(): void
    {
        $result = $this->command()->extractTable([
            'columns' => [
                'broken' => 'not-an-array',
                'header' => ['config' => ['type' => 'input']],
            ],
        ]);

        self::assertArrayNotHasKey('broken', $result['columns']);
        self::assertArrayHasKey('header', $result['columns']);
    }

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
            $this->command()->describeField($field),
        );
    }

    #[Test]
    public function describeFieldOmitsEmptyAndAbsentKeys(): void
    {
        $field = new InputFieldType('title', []);

        self::assertSame(['type' => 'input'], $this->command()->describeField($field));
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
            $this->command()->describeCapabilities($schema),
        );
    }

    #[Test]
    public function describeCapabilitiesReturnsNullAndFalseWhenNotSupported(): void
    {
        $schema = new TcaSchema('static_table', new FieldCollection([]), []);

        self::assertSame(
            ['softDelete' => null, 'workspace' => false, 'language' => false, 'sorting' => null],
            $this->command()->describeCapabilities($schema),
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

        self::assertSame(['text' => ['bodytext']], $this->command()->describeRecordTypes($schema));
    }

    #[Test]
    public function describeRecordTypesReturnsEmptyWhenTableHasNoTypeField(): void
    {
        $schema = new TcaSchema('sys_category', new FieldCollection([]), []);

        self::assertSame([], $this->command()->describeRecordTypes($schema));
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
            $this->command()->describeRelations($schema),
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

        self::assertSame(['sys_file_reference'], $this->command()->describeRelations($schema)['image']['toTables']);
    }

    #[Test]
    public function describeRelationsSkipsFieldsWithoutAnyResolvedRelation(): void
    {
        $schema = new TcaSchema('tt_content', new FieldCollection([
            'image' => new FileFieldType('image', [], []),
        ]), []);

        self::assertSame([], $this->command()->describeRelations($schema));
    }

    #[Test]
    public function executeListsAllSchemaTableNamesSortedWhenNoTableGiven(): void
    {
        $factory = $this->createMock(TcaSchemaFactory::class);
        $factory->method('all')->willReturn(new SchemaCollection([
            'tt_content' => new TcaSchema('tt_content', new FieldCollection([]), []),
            'be_users' => new TcaSchema('be_users', new FieldCollection([]), []),
            'pages' => new TcaSchema('pages', new FieldCollection([]), []),
        ]));

        $tester = new CommandTester(new TcaCommand($factory));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertSame(['be_users', 'pages', 'tt_content'], json_decode($tester->getDisplay(), true));
    }

    #[Test]
    public function executeFailsForAnUnknownTable(): void
    {
        $factory = $this->createMock(TcaSchemaFactory::class);
        $factory->method('has')->with('does_not_exist')->willReturn(false);

        $tester = new CommandTester(new TcaCommand($factory));
        $exitCode = $tester->execute(['table' => 'does_not_exist']);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('Unknown TCA table "does_not_exist".', $result['error']);
    }

    #[Test]
    public function executeDumpsCapabilitiesRecordTypesRelationsAndColumnsForATable(): void
    {
        $GLOBALS['TCA'] = [
            'pages' => [
                'ctrl' => ['title' => 'Pages', 'sortby' => 'sorting'],
                'columns' => [
                    'legacy_field' => ['label' => 'Legacy', 'config' => ['type' => 'input']],
                ],
            ],
        ];

        $schema = new TcaSchema('pages', new FieldCollection([
            'title' => new InputFieldType('title', ['label' => 'Title']),
            'media' => new FileFieldType('media', [], [new ActiveRelation('sys_file_reference', null)]),
        ]), ['sortby' => 'sorting']);

        $factory = $this->createMock(TcaSchemaFactory::class);
        $factory->method('has')->with('pages')->willReturn(true);
        $factory->method('get')->with('pages')->willReturn($schema);

        $tester = new CommandTester(new TcaCommand($factory));
        $exitCode = $tester->execute(['table' => 'pages']);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame(['title' => 'Pages', 'sortby' => 'sorting'], $result['ctrl']);
        self::assertSame(['softDelete' => null, 'workspace' => false, 'language' => false, 'sorting' => 'sorting'], $result['capabilities']);
        self::assertSame([], $result['recordTypes']);
        self::assertSame(['media' => ['type' => '', 'toTables' => ['sys_file_reference']]], $result['relations']);

        $columns = $result['columns'];
        self::assertIsArray($columns);
        self::assertSame(['label' => 'Title', 'type' => 'input'], $columns['title']);
        self::assertArrayHasKey('media', $columns);
        self::assertSame(['label' => 'Legacy', 'type' => 'input'], $columns['legacy_field']);
    }

    private function command(): TcaCommand
    {
        return new TcaCommand($this->createMock(TcaSchemaFactory::class));
    }
}
