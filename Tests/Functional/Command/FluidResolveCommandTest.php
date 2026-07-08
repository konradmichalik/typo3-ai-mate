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
 * FluidResolveCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class FluidResolveCommandTest extends FunctionalTestCase
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
            'setup' => ['EXT:typo3_ai_mate/Tests/Functional/Fixtures/fluid.typoscript'],
        ]);
    }

    #[Test]
    public function resolvesTheWinningFileFromTheHighestRootPathKey(): void
    {
        [$exitCode, $result] = $this->runCommand([
            'pageId' => '1',
            '--plugin' => 'plugin.tx_fixture',
            '--template' => 'News/List',
            '--partial' => 'Item',
            '--layout' => 'Default',
        ]);

        self::assertSame(0, $exitCode);
        self::assertSame('plugin.tx_fixture', $result['viewPath']);

        // Candidates are ordered by numeric key descending — highest key wins.
        self::assertSame(['20', '10'], array_column($result['templateRootPaths'], 'key'));
        self::assertTrue($result['templateRootPaths'][0]['exists']);

        self::assertStringEndsWith('Fluid/Override/Templates/News/List.html', $result['resolved']['templateRootPaths']['file']);
        self::assertStringEndsWith('Fluid/Base/Partials/Item.html', $result['resolved']['partialRootPaths']['file']);

        // The layout root path points to a missing directory: nothing resolves,
        // but the checked locations are reported.
        self::assertNull($result['resolved']['layoutRootPaths']['file']);
        self::assertNotEmpty($result['resolved']['layoutRootPaths']['checked']);
        self::assertFalse($result['layoutRootPaths'][0]['exists']);
    }

    #[Test]
    public function reportsTheCandidateChainWithoutResolvingWhenNoNameIsGiven(): void
    {
        [$exitCode, $result] = $this->runCommand(['pageId' => '1', '--plugin' => 'plugin.tx_fixture']);

        self::assertSame(0, $exitCode);
        self::assertCount(2, $result['templateRootPaths']);
        self::assertSame([], $result['resolved']);
    }

    #[Test]
    public function failsWithAReadableErrorWhenThePluginOptionIsMissing(): void
    {
        [$exitCode, $result] = $this->runCommand(['pageId' => '1']);

        self::assertSame(1, $exitCode);
        self::assertSame('--plugin (a TypoScript view path, e.g. plugin.tx_news_pi1) is required.', $result['error']);
    }

    #[Test]
    public function failsWithAReadableErrorForAnUnresolvablePage(): void
    {
        [$exitCode, $result] = $this->runCommand(['pageId' => '999', '--plugin' => 'plugin.tx_fixture']);

        self::assertSame(1, $exitCode);
        self::assertArrayHasKey('error', $result);
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:fluid:resolve');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
