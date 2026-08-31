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
 * FluidResolveCandidateLimitTest.
 *
 * Separate from FluidResolveCommandTest because it needs its own TypoScript: an
 * installation with more declared view paths than the miss answer will list.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class FluidResolveCandidateLimitTest extends FunctionalTestCase
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
            'setup' => ['EXT:typo3_ai_mate/Tests/Functional/Fixtures/fluid-many-view-paths.typoscript'],
        ]);
    }

    #[Test]
    public function saysThatTheCandidateListWasTruncated(): void
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:fluid:resolve');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['pageId' => '1', '--plugin' => 'plugin.tx_absent']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame(0, $exitCode);

        // Listing 50 of 51 without saying so lets a reader conclude their view
        // path is simply not declared anywhere.
        self::assertFalse($result['viewPathFound']);
        self::assertSame(51, $result['candidateCount']);
        self::assertCount(50, (array) $result['candidates']);
        self::assertStringContainsString('Showing the first 50 of 51.', (string) $result['_hint']);
    }
}
