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

use KonradMichalik\Typo3AiMate\Command\UpgradeCompatibilityCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Package\{PackageInterface, PackageManager};
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function count;

/**
 * UpgradeCompatibilityCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class UpgradeCompatibilityCommandTest extends FunctionalTestCase
{
    // Reads package metadata only; no database is needed.
    protected bool $initializeDatabase = false;

    // EXT:install provides LateBootService (autowired by UpgradeWizardsCommand),
    // which the extension's service definitions require to compile.
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
        __DIR__.'/../Fixtures/Extensions/scanner_fixture',
        __DIR__.'/../Fixtures/Extensions/compat_fixture',
    ];

    #[Test]
    public function reportsEveryNonCoreExtensionWithBlockingOnesFirst(): void
    {
        [$exitCode, $result] = $this->runRegisteredCommand('14.3');

        self::assertSame(0, $exitCode);
        self::assertSame('14.3', $result['target']);
        $extensions = $result['extensions'];
        self::assertIsArray($extensions);
        $keys = array_column($extensions, 'extension');
        self::assertContains('typo3_ai_mate', $keys);
        self::assertContains('scanner_fixture', $keys);
        self::assertNotContains('core', $keys, 'Core system extensions are not part of the report.');
        self::assertSame('compat_fixture', $keys[0]);
        // The testing framework adds its own helper extensions, so only the
        // blocking count and the total are fixed.
        $summary = $result['summary'];
        self::assertIsArray($summary);
        self::assertSame(['blocking', 'unknown', 'compatible'], array_keys($summary));
        self::assertSame(1, $summary['blocking']);
        self::assertSame(count($extensions), array_sum($summary));
    }

    #[Test]
    public function readsTheComposerConstraintAndFallsBackToExtEmconf(): void
    {
        [, $result] = $this->runRegisteredCommand('14.3');
        $byKey = $this->indexByExtension($result);

        // The v13 package manager builds a test extension's manifest from
        // ext_emconf.php, so composer.json is only read on v14.
        $fromComposer = (new Typo3Version())->getMajorVersion() >= 14;
        self::assertSame([
            'extension' => 'scanner_fixture',
            'package' => 'konradmichalik/scanner-fixture',
            'origin' => 'own',
            'constraint' => $fromComposer ? '^13.4 || ^14.0' : '13.4.0-14.99.99',
            'source' => $fromComposer ? 'composer.json' : 'ext_emconf.php',
            'status' => 'compatible',
        ], $byKey['scanner_fixture']);

        self::assertSame('12.4.0-12.4.99', $byKey['compat_fixture']['constraint']);
        self::assertSame('ext_emconf.php', $byKey['compat_fixture']['source']);
        self::assertSame('blocking', $byKey['compat_fixture']['status']);
    }

    #[Test]
    public function acceptsAFullTargetVersion(): void
    {
        [$exitCode, $result] = $this->runRegisteredCommand('12.4.5');
        $byKey = $this->indexByExtension($result);

        self::assertSame(0, $exitCode);
        self::assertSame('compatible', $byKey['compat_fixture']['status']);
        self::assertSame('blocking', $byKey['scanner_fixture']['status']);
    }

    #[Test]
    public function failsForAnInvalidTarget(): void
    {
        [$exitCode, $result] = $this->runRegisteredCommand('^14');

        self::assertSame(1, $exitCode);
        self::assertIsString($result['error']);
        self::assertStringContainsString('14.3', $result['error']);
    }

    #[Test]
    public function reportsAnExtensionWithoutAnyConstraintAsUnknown(): void
    {
        $projectPath = realpath(Environment::getProjectPath()) ?: Environment::getProjectPath();
        $package = self::createStub(PackageInterface::class);
        $package->method('getPackageKey')->willReturn('bare_ext');
        $package->method('getPackagePath')->willReturn($projectPath.'/vendor/acme/bare-ext/');
        $package->method('getValueFromComposerManifest')->willReturnMap([
            ['type', 'typo3-cms-extension'],
            ['name', 'acme/bare-ext'],
            ['require', null],
        ]);
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([$package]);

        $tester = new CommandTester(new UpgradeCompatibilityCommand($packageManager));
        $exitCode = $tester->execute(['--target' => '14.3']);
        $decoded = json_decode($tester->getDisplay(), true);

        self::assertIsArray($decoded);
        self::assertSame(0, $exitCode);
        self::assertSame([
            'extension' => 'bare_ext',
            'package' => 'acme/bare-ext',
            'origin' => 'thirdParty',
            'constraint' => null,
            'source' => null,
            'status' => 'unknown',
            'reason' => 'Neither composer.json nor ext_emconf.php declares a typo3/cms-core constraint.',
        ], $decoded['extensions'][0]);
    }

    #[Test]
    public function reportsAnUnboundedExtEmconfRangeAsUnknown(): void
    {
        $dir = sys_get_temp_dir().'/typo3-ai-mate-compat-'.bin2hex(random_bytes(8));
        mkdir($dir);
        file_put_contents($dir.'/ext_emconf.php', "<?php\n\$EM_CONF[\$_EXTKEY] = ['constraints' => ['depends' => ['typo3' => '0.0.0-0.0.0']]];\n");
        $package = self::createStub(PackageInterface::class);
        $package->method('getPackageKey')->willReturn('open_ext');
        $package->method('getPackagePath')->willReturn($dir.'/');
        $package->method('getValueFromComposerManifest')->willReturnMap([
            ['type', 'typo3-cms-extension'],
            ['name', 'acme/open-ext'],
            ['require', null],
        ]);
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([$package]);

        $tester = new CommandTester(new UpgradeCompatibilityCommand($packageManager));
        $tester->execute(['--target' => '14.3']);

        unlink($dir.'/ext_emconf.php');
        rmdir($dir);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        $entry = $decoded['extensions'][0];
        self::assertSame('0.0.0-0.0.0', $entry['constraint']);
        self::assertSame('ext_emconf.php', $entry['source']);
        self::assertSame('unknown', $entry['status']);
        self::assertStringContainsString('sets no version bound', $entry['reason']);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexByExtension(array $result): array
    {
        $extensions = $result['extensions'];
        self::assertIsArray($extensions);

        return array_column($extensions, null, 'extension');
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runRegisteredCommand(string $target): array
    {
        $tester = new CommandTester($this->get(CommandRegistry::class)->get('typo3-ai-mate:upgrade:compatibility'));
        $exitCode = $tester->execute(['--target' => $target]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
