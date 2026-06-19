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

use KonradMichalik\Typo3AiMate\Command\ExtensionScannerCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * ExtensionScannerCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ExtensionScannerCommandTest extends TestCase
{
    private ExtensionScannerCommand $command;

    protected function setUp(): void
    {
        $this->command = new ExtensionScannerCommand(
            self::createStub(PackageManager::class),
        );
    }

    #[Test]
    public function buildMatcherConfigurationsMapsConfigFileBasenamesToMatcherClasses(): void
    {
        $configs = $this->command->buildMatcherConfigurations([
            'ArrayDimensionMatcher.php',
            'ClassNameMatcher.php',
        ]);

        self::assertCount(2, $configs);
        self::assertSame(
            'TYPO3\\CMS\\Install\\ExtensionScanner\\Php\\Matcher\\ArrayDimensionMatcher',
            $configs[0]['class'],
        );
        self::assertSame(
            'EXT:install/Configuration/ExtensionScanner/Php/ArrayDimensionMatcher.php',
            $configs[0]['configurationFile'],
        );
    }

    #[Test]
    public function buildMatcherConfigurationsSkipsNonPhpAndUnknownMatcherClasses(): void
    {
        $configs = $this->command->buildMatcherConfigurations([
            'ClassNameMatcher.php',
            'README.md',
            'NotARealMatcher.php',
        ]);

        self::assertCount(1, $configs);
        self::assertSame(
            'TYPO3\\CMS\\Install\\ExtensionScanner\\Php\\Matcher\\ClassNameMatcher',
            $configs[0]['class'],
        );
    }
}
