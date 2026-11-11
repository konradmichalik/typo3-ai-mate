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
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SysLogCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class SysLogCommandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    #[Test]
    public function findsADataHandlerPermissionErrorByTableAndRecordUid(): void
    {
        $this->importCSVDataSet(__DIR__.'/../Fixtures/sys_log_permission_denied.csv');
        $backendUser = $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['tt_content' => [7 => ['header' => 'Changed']]], []);
        $dataHandler->process_datamap();

        [$exitCode, $result] = $this->runCommand(['--table' => 'tt_content', '--record-uid' => '7', '--error' => true]);

        self::assertSame(0, $exitCode);
        self::assertSame(1, $result['totalMatched']);
        $entry = $result['entries'][0];
        self::assertSame('db', $entry['type']);
        self::assertSame('update', $entry['action']);
        self::assertSame('userError', $entry['error']);
        self::assertSame('tt_content', $entry['table']);
        self::assertSame(7, $entry['recordUid']);
        self::assertSame(1, $entry['pageUid']);
        self::assertSame('Attempt to modify record tt_content:7 without permission or non-existing page', $entry['details']);
    }

    #[Test]
    public function neverReturnsTheIpAddressOrTheUserIdentity(): void
    {
        $this->importCSVDataSet(__DIR__.'/../Fixtures/sys_log_privacy.csv');

        [$exitCode, $result] = $this->runCommand([]);

        self::assertSame(0, $exitCode);
        self::assertSame(3, $result['totalMatched']);
        $encoded = (string) json_encode($result);
        self::assertStringNotContainsString('203.0.113.7', $encoded);
        self::assertStringNotContainsString('198.51.100.24', $encoded);
        self::assertStringNotContainsString('4711', $encoded);
        self::assertStringNotContainsString('jane.roe', $encoded);
        foreach ($result['entries'] as $entry) {
            self::assertSame(['lastSeen', 'count', 'type', 'action', 'error', 'table', 'recordUid', 'pageUid', 'details'], array_keys($entry));
        }
    }

    #[Test]
    public function returnsTheNewestEventFirst(): void
    {
        $this->importCSVDataSet(__DIR__.'/../Fixtures/sys_log_privacy.csv');

        [, $result] = $this->runCommand([]);

        self::assertSame('be_users', $result['entries'][0]['table']);
        self::assertSame('login', $result['entries'][2]['type']);
    }

    #[Test]
    public function filtersByErrorAndType(): void
    {
        $this->importCSVDataSet(__DIR__.'/../Fixtures/sys_log_privacy.csv');

        [, $errors] = $this->runCommand(['--error' => true]);
        [, $logins] = $this->runCommand(['--type' => 'login']);

        self::assertSame(1, $errors['totalMatched']);
        self::assertSame(9, $errors['entries'][0]['recordUid']);
        self::assertSame(1, $logins['totalMatched']);
        self::assertSame('login', $logins['entries'][0]['type']);
    }

    #[Test]
    public function sinceExcludesOlderEvents(): void
    {
        $this->importCSVDataSet(__DIR__.'/../Fixtures/sys_log_privacy.csv');

        [$exitCode, $result] = $this->runCommand(['--since' => '1d']);

        self::assertSame(0, $exitCode);
        self::assertSame(0, $result['totalMatched']);
        self::assertSame([], $result['entries']);
    }

    #[Test]
    public function limitCapsTheDistinctEventsAndSaysSo(): void
    {
        $this->importCSVDataSet(__DIR__.'/../Fixtures/sys_log_privacy.csv');

        [, $result] = $this->runCommand(['--limit' => '1']);

        self::assertCount(1, $result['entries']);
        self::assertSame(3, $result['distinct']);
        self::assertTrue($result['_truncated']);
    }

    #[Test]
    public function failsForAnUnknownType(): void
    {
        [$exitCode, $result] = $this->runCommand(['--type' => 'nope']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('type must be one of', (string) $result['error']);
    }

    #[Test]
    public function failsForANonNumericRecordUid(): void
    {
        [$exitCode, $result] = $this->runCommand(['--record-uid' => 'abc']);

        self::assertSame(1, $exitCode);
        self::assertArrayHasKey('error', $result);
    }

    /**
     * @param array<string, string|bool> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:syslog:search');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
