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
 * PageCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class PageCommandTest extends FunctionalTestCase
{
    // EXT:install provides LateBootService (autowired by UpgradeWizardsCommand);
    // EXT:frontend is needed to resolve the page TypoScript for USER_INT detection.
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
        $this->importCSVDataSet(__DIR__.'/../Fixtures/tt_content_basic.csv');
        GeneralUtility::makeInstance(SiteWriter::class)->createNewBasicSite('main', 1, 'https://example.com/');
    }

    #[Test]
    public function reportsPageCompositionForAGivenPageId(): void
    {
        [$exitCode, $result] = $this->runCommand(['pageId' => '1']);

        self::assertSame(0, $exitCode);
        self::assertSame(1, $result['page']['id']);
        self::assertCount(2, $result['content_elements']);
        self::assertContains('text', array_column($result['content_elements'], 'CType'));
        self::assertArrayHasKey('user_int_plugins', $result);
    }

    #[Test]
    public function resolvesThePageIdFromAUrl(): void
    {
        [$exitCode, $result] = $this->runCommand(['--url' => 'https://example.com/']);

        self::assertSame(0, $exitCode);
        self::assertSame(1, $result['page']['id']);
    }

    #[Test]
    public function failsForAnUnknownPage(): void
    {
        [$exitCode, $result] = $this->runCommand(['pageId' => '999']);

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
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:page:info');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
