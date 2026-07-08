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
use ReflectionMethod;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Package\{PackageInterface, PackageManager};
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function function_exists;

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

    #[Test]
    public function scanWithoutExtensionArgumentReportsARollupAcrossAllScannableExtensions(): void
    {
        [$exitCode, $result] = $this->runScanAll();

        self::assertSame(0, $exitCode);
        self::assertSame('summary', $result['mode']);
        // The framework-type core package is filtered out, only the fixture remains.
        self::assertSame(1, $result['totals']['extensionsScanned']);
        self::assertSame(1, $result['totals']['extensionsWithMatches']);
        self::assertSame(['scanner_fixture'], array_column($result['extensions'], 'extension'));
    }

    #[Test]
    public function scanAllWithFullFormatListsEveryScannedExtension(): void
    {
        [$exitCode, $result] = $this->runScanAll('full');

        self::assertSame(0, $exitCode);
        self::assertSame('full', $result['mode']);
        self::assertSame(['scanner_fixture'], array_column($result['extensions'], 'extension'));
        self::assertNotEmpty($result['extensions'][0]['matches']);
    }

    #[Test]
    public function scanAllRestrictedToOwnCodeStillCoversTheFixtureExtension(): void
    {
        // Test extensions are symlinked into the instance, so realpath() resolves
        // them outside the instance's vendor/ directory — they count as own code.
        [$exitCode, $result] = $this->runScanAll(null, true);

        self::assertSame(0, $exitCode);
        self::assertSame(['scanner_fixture'], array_column($result['extensions'], 'extension'));
        self::assertSame(0, $result['totals']['thirdPartyStrong'] + $result['totals']['thirdPartyWeak']);
    }

    #[Test]
    public function scanFailsWhenTheExtensionPathIsNotADirectory(): void
    {
        $package = self::createStub(PackageInterface::class);
        $package->method('getPackagePath')->willReturn('/does/not/exist/');
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('getPackage')->willReturn($package);

        $tester = new CommandTester(new ExtensionScannerCommand($packageManager));
        $exitCode = $tester->execute(['extension' => 'ghost_ext']);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertSame(1, $exitCode);
        self::assertIsString($decoded['error']);
        self::assertStringContainsString('is not a directory', $decoded['error']);
    }

    #[Test]
    public function scanSkipsFilesThatCannotBeParsed(): void
    {
        $dir = sys_get_temp_dir().'/typo3-ai-mate-scan-'.bin2hex(random_bytes(8));
        mkdir($dir);
        file_put_contents($dir.'/Broken.php', "<?php this is {{{ not valid php\n");

        $package = self::createStub(PackageInterface::class);
        $package->method('getPackagePath')->willReturn($dir.'/');
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('getPackage')->willReturn($package);

        $tester = new CommandTester(new ExtensionScannerCommand($packageManager));
        $exitCode = $tester->execute(['extension' => 'broken_ext']);

        unlink($dir.'/Broken.php');
        rmdir($dir);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertSame(0, $exitCode);
        self::assertSame(1, $decoded['statistics']['filesSkipped']);
        self::assertSame(0, $decoded['statistics']['filesScanned']);
        self::assertSame(0, $decoded['statistics']['matchCount']);
    }

    #[Test]
    public function scanSkipsFilesWhoseContentCannotBeRead(): void
    {
        if (function_exists('posix_getuid') && 0 === posix_getuid()) {
            self::markTestSkipped('Running as root: file permissions are not enforced.');
        }

        $dir = sys_get_temp_dir().'/typo3-ai-mate-scan-'.bin2hex(random_bytes(8));
        mkdir($dir);
        file_put_contents($dir.'/Unreadable.php', "<?php\n");
        chmod($dir.'/Unreadable.php', 0o000);

        $package = self::createStub(PackageInterface::class);
        $package->method('getPackagePath')->willReturn($dir.'/');
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('getPackage')->willReturn($package);

        $tester = new CommandTester(new ExtensionScannerCommand($packageManager));
        $exitCode = $tester->execute(['extension' => 'unreadable_ext']);

        chmod($dir.'/Unreadable.php', 0o644);
        unlink($dir.'/Unreadable.php');
        rmdir($dir);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertSame(0, $exitCode);
        self::assertSame(1, $decoded['statistics']['filesSkipped']);
        self::assertSame(0, $decoded['statistics']['filesScanned']);
    }

    #[Test]
    public function scanAllOwnCodeFiltersOutVendorPackages(): void
    {
        $projectPath = realpath(Environment::getProjectPath()) ?: Environment::getProjectPath();
        $vendorPackage = self::createStub(PackageInterface::class);
        $vendorPackage->method('getValueFromComposerManifest')->willReturn('typo3-cms-extension');
        $vendorPackage->method('getPackagePath')->willReturn($projectPath.'/vendor/acme/thing');
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([$vendorPackage]);

        $tester = new CommandTester(new ExtensionScannerCommand($packageManager));
        $exitCode = $tester->execute(['--own-code' => true]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertSame(0, $exitCode);
        self::assertSame(0, $decoded['totals']['extensionsScanned']);
        self::assertSame([], $decoded['extensions']);
    }

    #[Test]
    public function preloadMatcherConfigurationsSkipsMissingConfigurationFiles(): void
    {
        $command = new ExtensionScannerCommand($this->get(PackageManager::class));

        $preloaded = (new ReflectionMethod($command, 'preloadMatcherConfigurations'))->invoke($command, [[
            'class' => \TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ArrayDimensionMatcher::class,
            'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/DoesNotExist.php',
        ]]);

        self::assertSame([], $preloaded);
    }

    #[Test]
    public function preloadMatcherConfigurationsResolvesEachConfigurationFileToAnInlineArrayOnce(): void
    {
        $command = new ExtensionScannerCommand($this->get(PackageManager::class));
        $configurations = $command->buildMatcherConfigurations(['ArrayDimensionMatcher.php']);

        $preloaded = (new ReflectionMethod($command, 'preloadMatcherConfigurations'))->invoke($command, $configurations);

        self::assertCount(1, $preloaded);
        self::assertArrayNotHasKey('configurationFile', $preloaded[0]);
        self::assertSame(
            require GeneralUtility::getFileAbsFileName('EXT:install/Configuration/ExtensionScanner/Php/ArrayDimensionMatcher.php'),
            $preloaded[0]['configurationArray'],
        );
    }

    #[Test]
    public function lineContentReadsFromTheAlreadyParsedLinesInsteadOfRereadingTheFile(): void
    {
        $command = new ExtensionScannerCommand($this->get(PackageManager::class));
        $lineContent = new ReflectionMethod($command, 'lineContent');
        $lines = ['<?php', '  $foo = 1;  ', 'bar();'];

        self::assertSame('$foo = 1;', $lineContent->invoke($command, $lines, 2));
        self::assertSame('', $lineContent->invoke($command, $lines, 99));
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

    /**
     * Scan-all against a package manager that only reports the fixture extension
     * (plus a framework-type package that must be filtered out). The real
     * typo3_ai_mate package is symlinked to the repository root — scanning it
     * would traverse the whole vendor/ tree and take minutes.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runScanAll(?string $format = null, bool $ownOnly = false): array
    {
        $realPackageManager = $this->get(PackageManager::class);
        $fixture = $realPackageManager->getPackage('scanner_fixture');

        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([
            $realPackageManager->getPackage('core'),
            $fixture,
        ]);
        $packageManager->method('getPackage')->with('scanner_fixture')->willReturn($fixture);

        $tester = new CommandTester(new ExtensionScannerCommand($packageManager));
        $input = [];
        if (null !== $format) {
            $input['--format'] = $format;
        }
        if ($ownOnly) {
            $input['--own-code'] = true;
        }
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
