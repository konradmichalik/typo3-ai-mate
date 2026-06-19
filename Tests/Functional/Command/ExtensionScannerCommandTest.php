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

use KonradMichalik\Typo3AiMate\Command\ExtensionScannerCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * ExtensionScannerCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ExtensionScannerCommandTest extends FunctionalTestCase
{
    // The scanner is pure static analysis; skipping database setup avoids the
    // per-test database creation that fails on the oldest testing-framework.
    protected bool $initializeDatabase = false;

    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
        __DIR__.'/../Fixtures/Extensions/scanner_fixture',
    ];

    #[Test]
    public function scanReportsAGroupedSummaryForTheFixtureExtensionByDefault(): void
    {
        [$exitCode, $result] = $this->runScan('scanner_fixture');

        self::assertSame(0, $exitCode);
        self::assertSame('scanner_fixture', $result['extension']);
        self::assertSame('summary', $result['mode']);
        self::assertArrayHasKey('origin', $result);
        self::assertArrayHasKey('statistics', $result);
        self::assertGreaterThanOrEqual(1, $result['statistics']['filesScanned']);
        self::assertArrayHasKey('matchCount', $result['statistics']);
        self::assertArrayHasKey('strong', $result['statistics']);
        self::assertArrayHasKey('weak', $result['statistics']);
        self::assertArrayHasKey('matches', $result);
    }

    #[Test]
    public function scanSummaryGroupsMatchesByMessageWithTheAffectedFiles(): void
    {
        [, $result] = $this->runScan('scanner_fixture');

        self::assertNotEmpty($result['matches'], 'The fixture references a removed core class and must produce matches.');
        $first = $result['matches'][0];
        self::assertArrayHasKey('message', $first);
        self::assertArrayHasKey('count', $first);
        self::assertArrayHasKey('files', $first);

        $files = array_merge(...array_column($result['matches'], 'files'));
        self::assertContains('Classes/LegacyConsumer.php', $files);
    }

    #[Test]
    public function scanFullFormatReportsIndividualMatchesWithTheTruncationFlag(): void
    {
        [$exitCode, $result] = $this->runScan('scanner_fixture', 'full');

        self::assertSame(0, $exitCode);
        self::assertSame('full', $result['mode']);
        self::assertArrayHasKey('_truncated', $result);
        self::assertFalse($result['_truncated']);

        $files = array_column($result['matches'], 'file');
        self::assertContains('Classes/LegacyConsumer.php', $files);
    }

    #[Test]
    public function scanFailsForAnUnknownExtension(): void
    {
        [$exitCode, $result] = $this->runScan('this_extension_does_not_exist');

        self::assertSame(1, $exitCode);
        self::assertArrayHasKey('error', $result);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runScan(string $extension, ?string $format = null): array
    {
        $command = new ExtensionScannerCommand($this->get(PackageManager::class));
        $tester = new CommandTester($command);
        $input = ['extension' => $extension];
        if (null !== $format) {
            $input['--format'] = $format;
        }
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
