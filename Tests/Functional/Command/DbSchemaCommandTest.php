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
 * DbSchemaCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class DbSchemaCommandTest extends FunctionalTestCase
{
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
    }

    #[Test]
    public function listsTablesWithARowCountEstimate(): void
    {
        [$exitCode, $result] = $this->runCommand([]);

        self::assertSame(0, $exitCode);
        self::assertFalse($result['_truncated']);
        self::assertGreaterThan(0, $result['tableCount']);

        $byName = array_column($result['tables'], null, 'name');
        self::assertArrayHasKey('pages', $byName);
        self::assertSame(1, $byName['pages']['rowCountEstimate']);
    }

    #[Test]
    public function filtersTheTableListByPattern(): void
    {
        [$exitCode, $result] = $this->runCommand(['--pattern' => 'tt_conten']);

        self::assertSame(0, $exitCode);
        $names = array_column($result['tables'], 'name');
        self::assertContains('tt_content', $names);
        foreach ($names as $name) {
            self::assertStringContainsString('tt_conten', $name);
        }
    }

    #[Test]
    public function describesATablesColumnsAndIndexes(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'pages']);

        self::assertSame(0, $exitCode);
        self::assertSame('pages', $result['table']);

        $columnsByName = array_column($result['columns'], null, 'name');
        self::assertArrayHasKey('uid', $columnsByName);
        self::assertArrayHasKey('pid', $columnsByName);
        self::assertFalse($columnsByName['uid']['nullable']);
        $indexesByName = array_column($result['indexes'], null, 'name');
        self::assertArrayHasKey('primary', $indexesByName);
        self::assertTrue($indexesByName['primary']['unique']);
        self::assertSame(['uid'], $indexesByName['primary']['columns']);
        self::assertSame([], $result['foreignKeys']);
    }

    #[Test]
    public function failsForAnUnknownTable(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tx_does_not_exist']);

        self::assertSame(1, $exitCode);
        self::assertSame('Unknown table "tx_does_not_exist".', $result['error']);
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:db-schema:dump');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
