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

namespace KonradMichalik\Typo3AiMate\Tests\Unit;

use KonradMichalik\Typo3AiMate\Configuration;
use KonradMichalik\Typo3AiMate\Log\DeprecationBacktraceProcessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Core\{ApplicationContext, Environment};
use TYPO3\CMS\Core\Utility\ArrayUtility;

/**
 * ConfigurationTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ConfigurationTest extends TestCase
{
    private mixed $originalConfVars;

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
    public function deprecationTrackingIsActiveInDevelopmentContext(): void
    {
        $this->initializeEnvironment('Development');

        self::assertTrue(Configuration::isDeprecationTrackingActive());
    }

    #[Test]
    public function deprecationTrackingIsInactiveOutsideDevelopment(): void
    {
        $this->initializeEnvironment('Production');

        self::assertFalse(Configuration::isDeprecationTrackingActive());
    }

    #[Test]
    public function registerSkipsWhenConfVarsAreNotAnArray(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);

        Configuration::registerDeprecationBacktraceProcessor();

        self::assertArrayNotHasKey('TYPO3_CONF_VARS', $GLOBALS);
    }

    #[Test]
    public function registerAddsTheProcessorToTheDeprecationsChannel(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = [];

        Configuration::registerDeprecationBacktraceProcessor();

        self::assertTrue(ArrayUtility::isValidPath($GLOBALS['TYPO3_CONF_VARS'], $this->processorPath()));
    }

    #[Test]
    public function registerDoesNotOverwriteAnExistingProcessorConfiguration(): void
    {
        $existing = ['keep' => true];
        $GLOBALS['TYPO3_CONF_VARS'] = ArrayUtility::setValueByPath([], $this->processorPath(), $existing);

        Configuration::registerDeprecationBacktraceProcessor();

        self::assertSame($existing, ArrayUtility::getValueByPath($GLOBALS['TYPO3_CONF_VARS'], $this->processorPath()));
    }

    /**
     * @return list<string>
     */
    private function processorPath(): array
    {
        return ['LOG', 'TYPO3', 'CMS', 'deprecations', 'processorConfiguration', LogLevel::NOTICE, DeprecationBacktraceProcessor::class];
    }

    private function initializeEnvironment(string $context): void
    {
        $base = sys_get_temp_dir();
        Environment::initialize(
            new ApplicationContext($context),
            true,
            false,
            $base,
            $base,
            $base.'/var',
            $base.'/config',
            '',
            'UNIX',
        );
    }
}
