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

/**
 * RecordsCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RecordsCommandTest extends FunctionalTestCase
{
    // EXT:install provides LateBootService (autowired by UpgradeWizardsCommand),
    // which the extension's service definitions require to compile.
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Fixtures/pages_basic.csv');
        $this->importCSVDataSet(__DIR__.'/../Fixtures/tt_content_records.csv');
    }

    #[Test]
    public function returnsHiddenAndDeletedRowsWithFlagsByDefault(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--pid' => '1']);

        self::assertSame(0, $exitCode);
        self::assertSame('tt_content', $result['table']);
        self::assertFalse($result['restrictionsApplied']);
        self::assertSame(3, $result['count']);

        $byUid = array_column($result['rows'], null, 'uid');
        self::assertSame([], $byUid[1]['_flags']);
        self::assertSame(['hidden'], $byUid[2]['_flags']);
        self::assertSame(['deleted'], $byUid[3]['_flags']);
    }

    #[Test]
    public function filtersRowsByWhereConstraints(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--where' => 'header=Intro']);

        self::assertSame(0, $exitCode);
        self::assertSame(1, $result['count']);
        self::assertSame(1, $result['rows'][0]['uid']);
    }

    #[Test]
    public function failsForAnEmptyTableName(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => '']);

        self::assertSame(1, $exitCode);
        self::assertSame('Unknown table "".', $result['error']);
    }

    #[Test]
    public function compactModeReturnsCoreFieldsAndTruncatesLongText(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--uid' => '1', '--fields' => 'uid,bodytext']);

        self::assertSame(0, $exitCode);
        self::assertSame(['uid', 'bodytext', '_flags'], array_keys((array) $result['rows'][0]));
        $bodytext = $result['rows'][0]['bodytext'];
        self::assertStringStartsWith(str_repeat('a', 200), $bodytext);
        self::assertStringContainsString('…(+', $bodytext);
    }

    #[Test]
    public function defaultCompactRowCarriesLabelTypeAndTheEnableColumnsThatAreSet(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--pid' => '1']);

        self::assertSame(0, $exitCode);
        $byUid = array_column((array) $result['rows'], null, 'uid');

        foreach (['uid', 'pid', 'header', 'CType'] as $field) {
            self::assertArrayHasKey($field, (array) $byUid[1]);
        }
        // Not part of the compact selection at all.
        self::assertArrayNotHasKey('bodytext', (array) $byUid[1]);
        // hidden=0 / deleted=0 carry no information and _flags states visibility,
        // so they are omitted — but a hidden row reports hidden=1.
        self::assertArrayNotHasKey('hidden', (array) $byUid[1]);
        self::assertSame(1, $byUid[2]['hidden']);
    }

    #[Test]
    public function fullModeReturnsEveryColumnWithAValueUntruncated(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--uid' => '1', '--format' => 'full']);

        self::assertSame(0, $exitCode);
        $row = (array) $result['rows'][0];
        self::assertSame(246, mb_strlen((string) $row['bodytext']));
        // Bookkeeping columns stay out of a full selection unless named.
        self::assertArrayNotHasKey('l18n_diffsource', $row);
        self::assertArrayNotHasKey('t3ver_wsid', $row);
    }

    #[Test]
    public function anExplicitlyNamedColumnIsReportedEvenWhenEmpty(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--uid' => '1', '--fields' => 'uid,hidden,l18n_diffsource']);

        self::assertSame(0, $exitCode);
        $row = (array) $result['rows'][0];
        self::assertSame(0, $row['hidden']);
        self::assertArrayHasKey('l18n_diffsource', $row);
        self::assertArrayNotHasKey('_hint', $result);
    }

    #[Test]
    public function saysNoRowMatchedInsteadOfExplainingOmittedColumns(): void
    {
        // Zero rows is the answer. The default hint talks about columns dropped
        // from a row, which says nothing when there is no row to drop them from.
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--pid' => '9999']);

        self::assertSame(0, $exitCode);
        self::assertSame(0, $result['count']);
        self::assertStringContainsString('No row', (string) $result['_hint']);
        self::assertStringNotContainsString('omitted per row', (string) $result['_hint']);
    }

    #[Test]
    public function limitCapsResultsAndReportsWhenTruncated(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--pid' => '1', '--limit' => '1']);

        self::assertSame(0, $exitCode);
        self::assertSame(1, $result['count']);
        self::assertTrue($result['limited']);
    }

    #[Test]
    public function respectEnableFieldsHidesHiddenAndDeletedRows(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--pid' => '1', '--respect-enable-fields' => true]);

        self::assertSame(0, $exitCode);
        self::assertTrue($result['restrictionsApplied']);
        self::assertSame(1, $result['count']);
        self::assertSame(1, $result['rows'][0]['uid']);
    }

    #[Test]
    public function redactsSensitiveColumns(): void
    {
        $this->importCSVDataSet(__DIR__.'/../Fixtures/be_users_records.csv');

        [$exitCode, $result] = $this->runCommand(['table' => 'be_users', '--uid' => '1', '--fields' => 'uid,username,password']);

        self::assertSame(0, $exitCode);
        self::assertSame('admin', $result['rows'][0]['username']);
        self::assertSame('***', $result['rows'][0]['password']);
    }

    #[Test]
    public function redactsPersonalDataOfUserTables(): void
    {
        $this->importCSVDataSet(__DIR__.'/../Fixtures/be_users_records.csv');

        [$exitCode, $result] = $this->runCommand(['table' => 'be_users', '--uid' => '1', '--fields' => 'uid,username,email']);

        self::assertSame(0, $exitCode);
        self::assertSame('admin', $result['rows'][0]['username']);
        self::assertSame('***', $result['rows'][0]['email']);
    }

    #[Test]
    public function blocksSessionTables(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'be_sessions']);

        self::assertSame(1, $exitCode);
        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('blocked', (string) $result['error']);
    }

    #[Test]
    public function failsForNonNumericUid(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--uid' => 'abc']);

        self::assertSame(1, $exitCode);
        self::assertArrayHasKey('error', $result);
    }

    #[Test]
    public function failsForUnknownOrderByField(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--order-by' => 'ghost']);

        self::assertSame(1, $exitCode);
        self::assertArrayHasKey('error', $result);
    }

    #[Test]
    public function failsForAnUnknownTable(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tx_does_not_exist']);

        self::assertSame(1, $exitCode);
        self::assertArrayHasKey('error', $result);
    }

    #[Test]
    public function failsForAnUnknownFieldInWhere(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--where' => 'nope=1']);

        self::assertSame(1, $exitCode);
        self::assertArrayHasKey('error', $result);
        self::assertArrayHasKey('validColumns', $result);
    }

    /**
     * @param array<string, string|bool> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:records:query');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
