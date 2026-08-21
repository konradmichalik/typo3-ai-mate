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

use Composer\InstalledVersions;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use KonradMichalik\Ttt\Attribute\WithEnvironment;
use KonradMichalik\Typo3AiMate\Command\InfoCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Localization\{LanguageService, LanguageServiceFactory};
use TYPO3\CMS\Core\Package\{Package, PackageManager};
use TYPO3\CMS\Core\Schema\Field\{FieldCollection, StaticSelectFieldType};
use TYPO3\CMS\Core\Schema\Struct\SelectItem;
use TYPO3\CMS\Core\Schema\{TcaSchema, TcaSchemaFactory};

use function file_put_contents;
use function mkdir;

/**
 * InfoCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[WithEnvironment]
final class InfoCommandTest extends TestCase
{
    #[Test]
    public function describeDatabaseReportsThePlatformNameAndServerVersion(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('getServerVersion')->willReturn('3.45.1');
        $connectionPool = self::createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionByName')->willReturn($connection);

        $described = $this->command(connectionPool: $connectionPool)->describeDatabase();

        self::assertSame(['platform' => 'SQLite', 'version' => '3.45.1'], $described);
    }

    #[Test]
    public function describeExtensionsSplitsOwnFromThirdPartyAndIgnoresNonExtensionPackages(): void
    {
        $projectPath = Environment::getProjectPath();
        // OwnPackages::isOwn() canonicalises both sides via realpath(), so the
        // fake package paths must exist on disk or the comparison is unstable
        // (e.g. macOS resolving /var to /private/var only on one side).
        mkdir($projectPath.'/packages/typo3_ai_mate', 0o777, true);
        mkdir($projectPath.'/vendor/georgringer/news', 0o777, true);
        mkdir($projectPath.'/vendor/typo3/cms-core', 0o777, true);

        $ownPackage = $this->packageStub('typo3_ai_mate', 'typo3-cms-extension', $projectPath.'/packages/typo3_ai_mate');
        $thirdPartyPackage = $this->packageStub('news', 'typo3-cms-extension', $projectPath.'/vendor/georgringer/news');
        $frameworkPackage = $this->packageStub('core', 'typo3-cms-framework', $projectPath.'/vendor/typo3/cms-core');

        $packageManager = self::createStub(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([$ownPackage, $thirdPartyPackage, $frameworkPackage]);

        $described = $this->command(packageManager: $packageManager)->describeExtensions();

        self::assertSame(['typo3_ai_mate'], $described['own']);
        self::assertSame(['news'], $described['thirdParty']);
    }

    #[Test]
    public function describePackagesReportsOnlyInstalledCuratedPackages(): void
    {
        $packages = $this->command()->describePackages();

        self::assertArrayHasKey('konradmichalik/typo3-ai-mate', $packages);
        self::assertArrayHasKey('symfony/ai-mate', $packages);
        self::assertArrayHasKey('mcp/sdk', $packages);
        self::assertArrayHasKey('konradmichalik/typo3-request-profiler', $packages);
        foreach ($packages as $version) {
            self::assertNotSame('', $version);
        }
    }

    #[Test]
    public function describeProfilerReportsCliAvailabilityAndVersion(): void
    {
        $commandRegistry = self::createStub(CommandRegistry::class);
        $commandRegistry->method('has')->willReturn(true);

        $described = $this->command(commandRegistry: $commandRegistry)->describeProfiler();

        self::assertTrue($described['cliAvailable']);
        self::assertSame(InstalledVersions::getPrettyVersion('konradmichalik/typo3-request-profiler'), $described['version']);
    }

    #[Test]
    public function describeProfilerReportsAClosedActivationWindowWhenNoStateFileExists(): void
    {
        $commandRegistry = self::createStub(CommandRegistry::class);
        $commandRegistry->method('has')->willReturn(false);

        $described = $this->command(commandRegistry: $commandRegistry)->describeProfiler();

        self::assertFalse($described['cliAvailable']);
        self::assertFalse($described['activationWindowOpen']);
    }

    #[Test]
    public function describeProfilerReportsAnOpenActivationWindowWhileItHasNotExpired(): void
    {
        $logDir = Environment::getProjectPath().'/var/log';
        mkdir($logDir, 0o777, true);
        file_put_contents($logDir.'/profiler-activation-state.json', json_encode(['expiresAt' => time() + 600]));

        $described = $this->command()->describeProfiler();

        self::assertTrue($described['activationWindowOpen']);
    }

    #[Test]
    public function describeProfilerReportsAClosedActivationWindowOnceItHasExpired(): void
    {
        $logDir = Environment::getProjectPath().'/var/log';
        mkdir($logDir, 0o777, true);
        file_put_contents($logDir.'/profiler-activation-state.json', json_encode(['expiresAt' => time() - 600]));

        $described = $this->command()->describeProfiler();

        self::assertFalse($described['activationWindowOpen']);
    }

    #[Test]
    public function describeProfilerReportsDevelopmentContextIndependentlyOfTheActivationWindow(): void
    {
        self::assertFalse($this->command()->describeProfiler()['developmentContext']);
    }

    #[Test]
    #[WithEnvironment(context: 'Development')]
    public function describeProfilerReportsTheDevelopmentContextWhenActive(): void
    {
        self::assertTrue($this->command()->describeProfiler()['developmentContext']);
    }

    #[Test]
    public function describeToolClustersReportsBothClustersAsUnregisteredForAnUntouchedInstallation(): void
    {
        $clusters = $this->command()->describeToolClusters();

        // Nothing recorded, nothing logged: both clusters collapse to their
        // entry-point tool, and the reason says which one that is.
        self::assertFalse($clusters['profiler']['registered']);
        self::assertStringContainsString('typo3-profiler-start', $clusters['profiler']['reason']);
        self::assertFalse($clusters['logs']['registered']);
        self::assertStringContainsString('typo3-logs-tail', $clusters['logs']['reason']);
    }

    #[Test]
    public function describeToolClustersRegistersTheProfilerClusterOnceAProfileExists(): void
    {
        $profilesDir = Environment::getProjectPath().'/var/log/profiles';
        mkdir($profilesDir, 0o777, true);
        file_put_contents($profilesDir.'/abc.json', '{"token":"abc","schemaVersion":1}');

        self::assertTrue($this->command()->describeToolClusters()['profiler']['registered']);
    }

    #[Test]
    public function describeSelectItemResolvesTheLabelViaTheLanguageService(): void
    {
        $languageService = self::createStub(LanguageService::class);
        $languageService->method('sL')->willReturn('Header');
        $item = SelectItem::fromTcaItemArray(['label' => 'LLL:EXT:frontend/locallang.xlf:CType.header', 'value' => 'header', 'group' => 'default']);

        $described = $this->command()->describeSelectItem($item, $languageService);

        self::assertSame(['value' => 'header', 'label' => 'Header', 'group' => 'default'], $described);
    }

    #[Test]
    public function describeSelectFieldSkipsDividersAndNonStaticSelectFields(): void
    {
        $languageService = self::createStub(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);

        $field = new StaticSelectFieldType('CType', [
            'items' => [
                ['label' => 'Header', 'value' => 'header', 'group' => 'default'],
                ['label' => '---', 'value' => '--div--'],
                ['label' => 'Text', 'value' => 'text', 'group' => 'default'],
            ],
        ]);
        $schema = new TcaSchema('tt_content', new FieldCollection(['CType' => $field]), []);

        $described = $this->command()->describeSelectField($schema, 'CType', $languageService);

        self::assertSame(['header', 'text'], array_column($described, 'value'));
    }

    #[Test]
    public function describeSelectFieldReturnsAnEmptyListWhenTheFieldDoesNotExist(): void
    {
        $languageService = self::createStub(LanguageService::class);
        $schema = new TcaSchema('tt_content', new FieldCollection([]), []);

        self::assertSame([], $this->command()->describeSelectField($schema, 'list_type', $languageService));
    }

    #[Test]
    public function describeContentTypesOmitsListTypesWhenTheColumnDoesNotExist(): void
    {
        $field = new StaticSelectFieldType('CType', ['items' => [['label' => 'Header', 'value' => 'header']]]);
        $schema = new TcaSchema('tt_content', new FieldCollection(['CType' => $field]), []);
        $tcaSchemaFactory = self::createStub(TcaSchemaFactory::class);
        $tcaSchemaFactory->method('has')->willReturn(true);
        $tcaSchemaFactory->method('get')->willReturn($schema);

        $languageService = self::createStub(LanguageService::class);
        $languageService->method('sL')->willReturnArgument(0);

        $described = $this->command(tcaSchemaFactory: $tcaSchemaFactory)->describeContentTypes($languageService);

        self::assertArrayHasKey('cTypes', $described);
        self::assertArrayNotHasKey('listTypes', $described);
    }

    private function packageStub(string $key, string $type, string $path): Package
    {
        $package = self::createStub(Package::class);
        $package->method('getPackageKey')->willReturn($key);
        $package->method('getValueFromComposerManifest')->willReturn($type);
        $package->method('getPackagePath')->willReturn($path);

        return $package;
    }

    private function command(
        ?PackageManager $packageManager = null,
        ?ConnectionPool $connectionPool = null,
        ?CommandRegistry $commandRegistry = null,
        ?TcaSchemaFactory $tcaSchemaFactory = null,
    ): InfoCommand {
        return new InfoCommand(
            new Typo3Version(),
            $packageManager ?? self::createStub(PackageManager::class),
            $connectionPool ?? self::createStub(ConnectionPool::class),
            $commandRegistry ?? self::createStub(CommandRegistry::class),
            $tcaSchemaFactory ?? self::createStub(TcaSchemaFactory::class),
            self::createStub(LanguageServiceFactory::class),
        );
    }
}
