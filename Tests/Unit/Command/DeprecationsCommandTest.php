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

use KonradMichalik\Typo3AiMate\Command\DeprecationsCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Package\{PackageInterface, PackageManager};

/**
 * DeprecationsCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class DeprecationsCommandTest extends TestCase
{
    use WithTemporaryVarPath;

    private DeprecationsCommand $command;

    private mixed $originalConfVars = null;

    protected function setUp(): void
    {
        $packageManager = self::createStub(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([]);
        $this->command = new DeprecationsCommand($packageManager);
        $this->originalConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $this->initVarPath();
    }

    protected function tearDown(): void
    {
        $this->cleanupVarPath();
        if (null === $this->originalConfVars) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->originalConfVars;
        }
    }

    #[Test]
    public function isDeprecationEntryMatchesOnlyTheDeprecationsChannel(): void
    {
        self::assertTrue($this->command->isDeprecationEntry(['component' => 'TYPO3.CMS.deprecations']));
        self::assertFalse($this->command->isDeprecationEntry(['component' => 'TYPO3.CMS.Core.Error']));
        self::assertFalse($this->command->isDeprecationEntry([]));
    }

    #[Test]
    public function aggregateDeduplicatesByMessageCountsAndKeepsLastSeen(): void
    {
        $deprecations = $this->command->aggregate([
            ['message' => 'Foo is deprecated', 'component' => 'TYPO3.CMS.deprecations', 'time' => 'T1', 'request_id' => 'r1'],
            ['message' => 'Bar is deprecated', 'component' => 'TYPO3.CMS.deprecations', 'time' => 'T2', 'request_id' => 'r2'],
            ['message' => 'Foo is deprecated', 'component' => 'TYPO3.CMS.deprecations', 'time' => 'T3', 'request_id' => 'r3'],
        ]);

        // Most frequent first.
        self::assertSame('Foo is deprecated', $deprecations[0]['message']);
        self::assertSame(2, $deprecations[0]['count']);
        self::assertSame('T3', $deprecations[0]['lastSeen']);
        self::assertSame('r1', $deprecations[0]['exampleRequestId']);

        self::assertSame('Bar is deprecated', $deprecations[1]['message']);
        self::assertSame(1, $deprecations[1]['count']);
    }

    #[Test]
    public function isDeprecationLoggingEnabledDetectsTheDisabledDefault(): void
    {
        $defaultDisabled = [
            'NOTICE' => [
                'TYPO3\\CMS\\Core\\Log\\Writer\\FileWriter' => [
                    'logFileInfix' => 'deprecations',
                    'disabled' => true,
                ],
            ],
        ];
        self::assertFalse($this->command->isDeprecationLoggingEnabled($defaultDisabled));

        $enabled = [
            'NOTICE' => [
                'TYPO3\\CMS\\Core\\Log\\Writer\\FileWriter' => [
                    'logFileInfix' => 'deprecations',
                ],
            ],
        ];
        self::assertTrue($this->command->isDeprecationLoggingEnabled($enabled));

        self::assertFalse($this->command->isDeprecationLoggingEnabled([]));
    }

    #[Test]
    public function executeAggregatesOnlyDeprecationChannelEntriesAndReportsLoggingState(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['LOG' => ['TYPO3' => ['CMS' => ['deprecations' => ['writerConfiguration' => [
            'NOTICE' => ['TYPO3\\CMS\\Core\\Log\\Writer\\FileWriter' => ['logFileInfix' => 'deprecations']],
        ]]]]]];

        $this->writeLog('deprecations', [
            'Mon, 15 Jun 2026 16:16:25 +0200 [NOTICE] request="r1" component="TYPO3.CMS.deprecations": Foo is deprecated',
            'Mon, 15 Jun 2026 16:16:26 +0200 [NOTICE] request="r2" component="TYPO3.CMS.deprecations": Foo is deprecated',
            'Mon, 15 Jun 2026 16:16:27 +0200 [INFO] request="r3" component="TYPO3.CMS.Core": Not a deprecation',
        ]);

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertTrue($result['loggingEnabled']);
        $deprecations = $result['deprecations'];
        self::assertIsArray($deprecations);
        self::assertCount(1, $deprecations);
        $first = $deprecations[0];
        self::assertIsArray($first);
        self::assertSame('Foo is deprecated', $first['message']);
        self::assertSame(2, $first['count']);
        // Origins are attached; with no own packages registered the list is empty.
        self::assertArrayHasKey('origins', $first);
        self::assertSame([], $first['origins']);
    }

    #[Test]
    public function executeResolvesTheCallerInOwnCodeStatically(): void
    {
        $packageDir = sys_get_temp_dir().'/typo3-ai-mate-own-'.bin2hex(random_bytes(8));
        mkdir($packageDir.'/Classes', 0o777, true);
        file_put_contents($packageDir.'/Classes/Caller.php', "<?php\n\$renderer->useNonce(true);\n");

        $package = self::createStub(PackageInterface::class);
        $package->method('getValueFromComposerManifest')->willReturn('typo3-cms-extension');
        $package->method('getPackagePath')->willReturn($packageDir);
        $package->method('getPackageKey')->willReturn('my_ext');
        $packageManager = self::createStub(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([$package]);

        $GLOBALS['TYPO3_CONF_VARS'] = ['LOG' => ['TYPO3' => ['CMS' => ['deprecations' => ['writerConfiguration' => [
            'NOTICE' => ['TYPO3\\CMS\\Core\\Log\\Writer\\FileWriter' => ['logFileInfix' => 'deprecations']],
        ]]]]]];
        $this->writeLog('deprecations', [
            'Mon, 15 Jun 2026 16:16:25 +0200 [NOTICE] request="r1" component="TYPO3.CMS.deprecations": Argument $useNonce is deprecated',
        ]);

        $tester = new CommandTester(new DeprecationsCommand($packageManager));
        $tester->execute([]);

        $this->removeDir($packageDir);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        $deprecations = $result['deprecations'];
        self::assertIsArray($deprecations);
        $first = $deprecations[0];
        self::assertIsArray($first);
        $origins = $first['origins'];
        self::assertIsArray($origins);
        self::assertNotEmpty($origins);
        $origin = $origins[0];
        self::assertIsArray($origin);
        self::assertSame('my_ext/Classes/Caller.php', $origin['file']);
        self::assertSame('useNonce', $origin['symbol']);
        self::assertSame('static', $origin['via']);
    }

    #[Test]
    public function executePrefersTheLoggedBacktraceOriginAndCleansTheMessage(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['LOG' => ['TYPO3' => ['CMS' => ['deprecations' => ['writerConfiguration' => [
            'NOTICE' => ['TYPO3\\CMS\\Core\\Log\\Writer\\FileWriter' => ['logFileInfix' => 'deprecations']],
        ]]]]]];
        // FileWriter appends the processor's data as " - {json}" (slashes escaped).
        $this->writeLog('deprecations', [
            'Mon, 15 Jun 2026 16:16:25 +0200 [NOTICE] request="r1" component="TYPO3.CMS.deprecations": Foo is deprecated - {"typo3-ai-mate-origin":"packages\\/my_ext\\/Classes\\/Caller.php:42"}',
        ]);

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        $deprecations = $result['deprecations'];
        self::assertIsArray($deprecations);
        $first = $deprecations[0];
        self::assertIsArray($first);
        // The data tail is stripped from the displayed message.
        self::assertSame('Foo is deprecated', $first['message']);
        $origins = $first['origins'];
        self::assertIsArray($origins);
        $origin = $origins[0];
        self::assertIsArray($origin);
        self::assertSame('packages/my_ext/Classes/Caller.php', $origin['file']);
        self::assertSame(42, $origin['line']);
        self::assertSame('logged', $origin['via']);
        self::assertSame('high', $origin['confidence']);
    }

    private function removeDir(string $dir): void
    {
        $files = glob($dir.'/*') ?: [];
        foreach ($files as $file) {
            is_dir($file) ? $this->removeDir($file) : unlink($file);
        }
        @rmdir($dir);
    }
}
