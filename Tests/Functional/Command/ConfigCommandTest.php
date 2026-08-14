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
 * ConfigCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ConfigCommandTest extends FunctionalTestCase
{
    // EXT:install provides LateBootService (autowired by UpgradeWizardsCommand),
    // which the extension's service definitions require to compile.
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    #[Test]
    public function realEncryptionKeyNeverLeaksThroughAnyPath(): void
    {
        [$exitCodeDirect, $direct] = $this->runCommand(['--path' => 'SYS/encryptionKey']);
        self::assertSame(0, $exitCodeDirect);
        self::assertSame('[redacted]', $direct['value']);

        [$exitCodeSys, $sys] = $this->runCommand(['--path' => 'SYS']);
        self::assertSame(0, $exitCodeSys);
        $sysValue = $sys['value'];
        self::assertIsArray($sysValue);
        self::assertSame('[redacted]', $sysValue['encryptionKey']);

        [$exitCodeRoot, $root] = $this->runCommand([]);
        self::assertSame(0, $exitCodeRoot);
        self::assertStringNotContainsString((string) $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'], json_encode($root) ?: '');
    }

    #[Test]
    public function aRealDatabasePasswordIsMaskedUnderTheDbPath(): void
    {
        $originalDbConfig = $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] ?? [];
        $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['password'] = 'a-real-looking-secret';

        try {
            [$exitCode, $result] = $this->runCommand(['--path' => 'DB/Connections/Default']);

            self::assertSame(0, $exitCode);
            $value = $result['value'];
            self::assertIsArray($value);
            self::assertSame('[redacted]', $value['password']);
        } finally {
            $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = $originalDbConfig;
        }
    }

    #[Test]
    public function defaultOutputStaysCompactAndExposesFeatureToggles(): void
    {
        [$exitCode, $result] = $this->runCommand([]);

        self::assertSame(0, $exitCode);
        self::assertArrayHasKey('keys', $result);
        self::assertContains('SYS', $result['keys']);
        self::assertContains('DB', $result['keys']);
        self::assertArrayHasKey('features', $result);
        self::assertArrayNotHasKey('DB', $result);
    }

    #[Test]
    public function featureTogglesAreRetrievableOnTheirOwn(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['features']['typo3AiMate.testToggle'] = true;

        [$exitCode, $result] = $this->runCommand(['--section' => 'features']);

        self::assertSame(0, $exitCode);
        self::assertTrue($result['features']['typo3AiMate.testToggle']);
    }

    #[Test]
    public function failsForAnUnknownConfigurationPath(): void
    {
        [$exitCode, $result] = $this->runCommand(['--path' => 'GHOST/does/not/exist']);

        self::assertSame(1, $exitCode);
        self::assertSame('Unknown configuration path "GHOST/does/not/exist".', $result['error']);
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:config:dump');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
