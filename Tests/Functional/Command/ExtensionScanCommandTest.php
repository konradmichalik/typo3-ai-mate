<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <km@move-elevator.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Tests\Functional\Command;

use KonradMichalik\Typo3AiMate\Command\ExtensionScanCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * ExtensionScanCommandTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
final class ExtensionScanCommandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
        __DIR__.'/../Fixtures/Extensions/scanner_fixture',
    ];

    #[Test]
    public function scanReportsAStructuredResultForTheFixtureExtension(): void
    {
        [$exitCode, $result] = $this->runScan('scanner_fixture');

        self::assertSame(0, $exitCode);
        self::assertSame('scanner_fixture', $result['extension']);
        self::assertArrayHasKey('statistics', $result);
        self::assertGreaterThanOrEqual(1, $result['statistics']['filesScanned']);
        self::assertArrayHasKey('matches', $result);
    }

    #[Test]
    public function scanFlagsRemovedCoreApiUsageInTheFixture(): void
    {
        [, $result] = $this->runScan('scanner_fixture');

        $messages = array_column($result['matches'], 'message');
        self::assertNotEmpty($result['matches'], 'The fixture references a removed core class and must produce matches.');

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
    private function runScan(string $extension): array
    {
        $command = new ExtensionScanCommand($this->get(PackageManager::class));
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['extension' => $extension]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
