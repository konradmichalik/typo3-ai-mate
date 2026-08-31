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

namespace KonradMichalik\Typo3AiMate\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function count;

/**
 * TcaCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class TcaCommandTest extends FunctionalTestCase
{
    // EXT:install provides LateBootService (autowired by UpgradeWizardsCommand),
    // which the extension's service definitions require to compile.
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    #[Test]
    public function listsAllTableNamesSortedWhenNoTableGiven(): void
    {
        [$exitCode, $result] = $this->runCommand([]);

        self::assertSame(0, $exitCode);
        self::assertIsArray($result);
        self::assertContains('pages', $result);
        self::assertContains('tt_content', $result);
        self::assertSame($result, array_values($result));
        $sorted = $result;
        sort($sorted);
        self::assertSame($sorted, $result);
    }

    #[Test]
    public function dumpsCapabilitiesRecordTypesRelationsAndColumnsForTtContent(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content']);

        self::assertSame(0, $exitCode);

        $ctrl = $result['ctrl'];
        self::assertIsArray($ctrl);
        self::assertSame('CType', $ctrl['type']);
        self::assertSame('sorting', $ctrl['sortby']);

        $capabilities = $result['capabilities'];
        self::assertIsArray($capabilities);
        self::assertSame('deleted', $capabilities['softDelete']);
        self::assertTrue($capabilities['workspace']);
        self::assertTrue($capabilities['language']);
        self::assertSame('sorting', $capabilities['sorting']);

        $recordTypes = $result['recordTypes'];
        self::assertIsArray($recordTypes);
        $shared = (array) $recordTypes['shared'];
        $types = (array) $recordTypes['types'];
        self::assertArrayHasKey('text', $types);
        // Every record type carries CType, so it is stated once instead of per type.
        self::assertContains('CType', $shared);
        self::assertNotContains('CType', (array) $types['text']);
        self::assertContains('bodytext', [...$shared, ...(array) $types['text']]);

        $relations = $result['relations'];
        self::assertIsArray($relations);
        self::assertArrayHasKey('image', $relations);
        $imageRelation = $relations['image'];
        self::assertIsArray($imageRelation);
        self::assertSame('1:n', $imageRelation['type']);
        self::assertSame(['sys_file_reference'], $imageRelation['toTables']);

        $columns = $result['columns'];
        self::assertIsArray($columns);
        self::assertArrayHasKey('header', $columns);
        $header = $columns['header'];
        self::assertIsArray($header);
        self::assertSame('input', $header['type']);
    }

    #[Test]
    public function aRecordTypeFilterShrinksTheResponseToThatType(): void
    {
        [$exitCode, $full] = $this->runCommand(['table' => 'tt_content']);
        self::assertSame(0, $exitCode);

        [$exitCode, $scoped] = $this->runCommand(['table' => 'tt_content', '--record-type' => 'text']);

        self::assertSame(0, $exitCode);
        self::assertSame(['text'], array_keys((array) ((array) $scoped['recordTypes'])['types']));
        self::assertLessThan(
            count((array) $full['columns']),
            count((array) $scoped['columns']),
            'A record type carries fewer fields than the whole table.',
        );
        self::assertArrayHasKey('bodytext', (array) $scoped['columns']);
        self::assertStringContainsString('recordType "text"', (string) $scoped['_hint']);
    }

    #[Test]
    public function aFieldFilterReturnsOnlyTheRequestedColumns(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--fields' => 'header,bodytext']);

        self::assertSame(0, $exitCode);
        self::assertSame(['header', 'bodytext'], array_keys((array) $result['columns']));

        // The record types are reported as "which of these types show the
        // requested fields", not as every type's complete field list.
        $types = (array) ((array) $result['recordTypes'])['types'];
        self::assertContains('bodytext', (array) $types['text']);
        foreach ($types as $fields) {
            self::assertEmpty(array_diff((array) $fields, ['header', 'bodytext']));
        }
    }

    #[Test]
    public function answersAnUnknownRecordTypeWithTheAvailableOnes(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--record-type' => 'does_not_exist']);

        self::assertSame(0, $exitCode);
        self::assertFalse($result['recordTypeFound']);
        self::assertContains('text', (array) $result['availableRecordTypes']);
    }

    #[Test]
    public function saysThatATableWithoutRecordTypesCannotBeFilteredByOne(): void
    {
        // be_groups has no type field in ctrl, so there is no value the filter
        // could ever accept. Handing back an empty availableRecordTypes without
        // saying why reads like the table has types and none matched.
        [$exitCode, $result] = $this->runCommand(['table' => 'be_groups', '--record-type' => 'anything']);

        self::assertSame(0, $exitCode);
        self::assertFalse($result['recordTypeFound']);
        self::assertSame([], (array) $result['availableRecordTypes']);
        self::assertStringContainsString('no record types', (string) $result['_hint']);
    }

    #[Test]
    public function failsForAnUnknownTable(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tx_does_not_exist']);

        self::assertSame(1, $exitCode);
        self::assertSame('Unknown TCA table "tx_does_not_exist".', $result['error']);
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:tca:dump');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
