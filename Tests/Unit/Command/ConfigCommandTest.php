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

use KonradMichalik\Typo3AiMate\Command\ConfigCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ConfigCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ConfigCommandTest extends TestCase
{
    private mixed $originalConfVars = null;

    protected function setUp(): void
    {
        $this->originalConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
    }

    protected function tearDown(): void
    {
        if (null === $this->originalConfVars) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->originalConfVars;
        }
    }

    #[Test]
    public function traverseResolvesANestedPath(): void
    {
        [$found, $value] = (new ConfigCommand())->traverse(['SYS' => ['features' => ['foo' => true]]], 'SYS/features/foo');

        self::assertTrue($found);
        self::assertTrue($value);
    }

    #[Test]
    public function traverseFailsForAMissingSegment(): void
    {
        [$found, $value] = (new ConfigCommand())->traverse(['SYS' => []], 'SYS/features');

        self::assertFalse($found);
        self::assertNull($value);
    }

    #[Test]
    public function traverseFailsWhenAnIntermediateValueIsNotAnArray(): void
    {
        [$found] = (new ConfigCommand())->traverse(['SYS' => 'not-an-array'], 'SYS/features');

        self::assertFalse($found);
    }

    #[Test]
    public function executeReturnsCompactTopLevelKeysAndFeaturesByDefault(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = [
            'SYS' => ['features' => ['foo' => true], 'encryptionKey' => 'super-secret'],
            'FE' => ['some' => 'thing'],
            'DB' => ['Connections' => []],
        ];

        $tester = new CommandTester(new ConfigCommand());
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame(['SYS', 'FE', 'DB'], $result['keys']);
        self::assertSame(['foo' => true], $result['features']);
        self::assertArrayNotHasKey('encryptionKey', $result);
    }

    #[Test]
    public function executeResolvesAConfvarsPathAndMasksIt(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['SYS' => ['encryptionKey' => 'super-secret', 'sitename' => 'Acme']];

        $tester = new CommandTester(new ConfigCommand());
        $tester->execute(['--path' => 'SYS']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('SYS', $result['path']);
        self::assertSame(['encryptionKey' => '[redacted]', 'sitename' => 'Acme'], $result['value']);
    }

    #[Test]
    public function executeMasksTheValueWhenThePathPointsDirectlyAtASensitiveKey(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['SYS' => ['encryptionKey' => 'super-secret']];

        $tester = new CommandTester(new ConfigCommand());
        $tester->execute(['--path' => 'SYS/encryptionKey']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('[redacted]', $result['value']);
    }

    #[Test]
    public function executeFailsForAnUnknownConfvarsPath(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['SYS' => []];

        $tester = new CommandTester(new ConfigCommand());
        $exitCode = $tester->execute(['--path' => 'GHOST/path']);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('Unknown configuration path "GHOST/path".', $result['error']);
    }

    #[Test]
    public function executeReturnsAllFeatureTogglesForTheFeaturesSection(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['SYS' => ['features' => ['foo' => true, 'bar' => false]]];

        $tester = new CommandTester(new ConfigCommand());
        $tester->execute(['--section' => 'features']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame(['foo' => true, 'bar' => false], $result['features']);
    }

    #[Test]
    public function executeResolvesASingleFeatureToggleByPath(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['SYS' => ['features' => ['foo' => true]]];

        $tester = new CommandTester(new ConfigCommand());
        $tester->execute(['--section' => 'features', '--path' => 'foo']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('foo', $result['path']);
        self::assertTrue($result['value']);
    }

    #[Test]
    public function executeFailsForAnUnknownFeatureTogglePath(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['SYS' => ['features' => []]];

        $tester = new CommandTester(new ConfigCommand());
        $exitCode = $tester->execute(['--section' => 'features', '--path' => 'ghost']);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('Unknown feature toggle path "ghost".', $result['error']);
    }

    #[Test]
    public function executeListsExtensionKeysHavingConfigurationForTheExtensionSectionWithoutPath(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['EXTENSIONS' => ['beta_ext' => [], 'alpha_ext' => []]];

        $tester = new CommandTester(new ConfigCommand());
        $tester->execute(['--section' => 'extension']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame(['alpha_ext', 'beta_ext'], $result['extensions']);
    }

    #[Test]
    public function executeResolvesOneExtensionsConfigurationAndMasksIt(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['EXTENSIONS' => ['my_ext' => ['apiKey' => 'xyz', 'timeout' => 30]]];

        $tester = new CommandTester(new ConfigCommand());
        $tester->execute(['--section' => 'extension', '--path' => 'my_ext']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('my_ext', $result['path']);
        self::assertSame(['apiKey' => '[redacted]', 'timeout' => 30], $result['value']);
    }

    #[Test]
    public function executeFailsForAnUnknownExtensionConfigurationPath(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['EXTENSIONS' => []];

        $tester = new CommandTester(new ConfigCommand());
        $exitCode = $tester->execute(['--section' => 'extension', '--path' => 'does_not_exist']);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('Unknown extension configuration path "does_not_exist".', $result['error']);
    }

    #[Test]
    public function executeFallsBackToConfvarsForAnUnknownSectionOption(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['SYS' => ['features' => []]];

        $tester = new CommandTester(new ConfigCommand());
        $exitCode = $tester->execute(['--section' => 'bogus']);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertArrayHasKey('keys', $result);
    }
}
