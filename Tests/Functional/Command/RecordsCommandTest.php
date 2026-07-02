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
    public function compactModeReturnsCoreFieldsAndTruncatesLongText(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--uid' => '1', '--fields' => 'uid,bodytext']);

        self::assertSame(0, $exitCode);
        self::assertSame(['uid', 'bodytext'], $result['fields']);
        $bodytext = $result['rows'][0]['bodytext'];
        self::assertStringStartsWith(str_repeat('a', 200), $bodytext);
        self::assertStringContainsString('…(+', $bodytext);
    }

    #[Test]
    public function defaultCompactFieldSetIncludesLabelTypeAndEnableColumns(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--uid' => '1']);

        self::assertSame(0, $exitCode);
        foreach (['uid', 'pid', 'header', 'CType', 'hidden', 'deleted'] as $field) {
            self::assertContains($field, $result['fields']);
        }
        self::assertNotContains('bodytext', $result['fields']);
    }

    #[Test]
    public function fullModeReturnsAllColumnsUntruncated(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', '--uid' => '1', '--format' => 'full']);

        self::assertSame(0, $exitCode);
        self::assertContains('bodytext', $result['fields']);
        self::assertSame(246, mb_strlen((string) $result['rows'][0]['bodytext']));
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
