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
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * TypoScriptCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class TypoScriptCommandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'install',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Fixtures/pages_basic.csv');
        GeneralUtility::makeInstance(SiteWriter::class)->createNewBasicSite('main', 1, 'https://example.com/');
        $this->setUpFrontendRootPage(1, [
            'setup' => ['EXT:typo3_ai_mate/Tests/Functional/Fixtures/setup.typoscript'],
            'constants' => ['EXT:typo3_ai_mate/Tests/Functional/Fixtures/constants.typoscript'],
        ]);
    }

    #[Test]
    public function dumpsResolvedSetupScopedToAPath(): void
    {
        [$exitCode, $result] = $this->runCommand(['pageId' => '1', '--path' => 'lib.foo']);

        self::assertSame(0, $exitCode);
        self::assertSame(['value' => 'bar'], $result);
    }

    #[Test]
    public function dumpsResolvedConstants(): void
    {
        [$exitCode, $result] = $this->runCommand(['pageId' => '1', '--type' => 'constants', '--full' => true]);

        self::assertSame(0, $exitCode);
        self::assertSame('1', $result['myconst']);
    }

    #[Test]
    public function returnsTopLevelOverviewByDefault(): void
    {
        [$exitCode, $result] = $this->runCommand(['pageId' => '1']);

        self::assertSame(0, $exitCode);
        self::assertArrayHasKey('_hint', $result);
        // lib is an object branch, shown as a child-count descriptor rather than expanded.
        self::assertArrayHasKey('lib.', $result);
        self::assertStringContainsString('keys', (string) $result['lib.']);
    }

    #[Test]
    public function answersAPathMissWithTheSiblingsThatDoExist(): void
    {
        [$exitCode, $result] = $this->runCommand(['pageId' => '1', '--path' => 'lib.missing']);

        // A miss is a successful answer, not a failed call.
        self::assertSame(0, $exitCode);
        self::assertFalse($result['found']);
        self::assertSame('lib', $result['resolvedUpTo']);
        self::assertContains('foo', $result['siblings']);
        self::assertArrayHasKey('_hint', $result);
    }

    #[Test]
    public function failsWithAReadableErrorForAnUnresolvablePage(): void
    {
        [$exitCode, $result] = $this->runCommand(['pageId' => '999']);

        self::assertSame(1, $exitCode);
        self::assertArrayHasKey('error', $result);
        self::assertIsString($result['error']);
        self::assertStringContainsString('999', $result['error']);
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:typoscript:dump');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
