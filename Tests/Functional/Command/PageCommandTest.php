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

use KonradMichalik\Typo3AiMate\Command\PageCommand;
use KonradMichalik\Typo3AiMate\Service\TypoScriptResolver;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
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

    #[Test]
    public function failsWhenNeitherPageIdNorUrlIsGiven(): void
    {
        [$exitCode, $result] = $this->runCommand([]);

        self::assertSame(1, $exitCode);
        self::assertSame('No resolvable page id (pass a pageId argument or a matching --url).', $result['error']);
    }

    #[Test]
    public function failsForAUrlOutsideTheConfiguredSites(): void
    {
        [$exitCode, $result] = $this->runCommand(['--url' => 'https://not-configured.example/']);

        self::assertSame(1, $exitCode);
        self::assertArrayHasKey('error', $result);
    }

    #[Test]
    public function urlResolutionSurvivesASiteFinderFailure(): void
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willThrowException(new RuntimeException('sites unavailable'));
        $command = new PageCommand($this->get(ConnectionPool::class), $siteFinder, $this->get(TypoScriptResolver::class));

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--url' => 'https://example.com/']);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertArrayHasKey('error', $result);
    }

    #[Test]
    public function reportsThePageEvenWhenItsTypoScriptCannotBeResolved(): void
    {
        // Page 2 is a standalone root without a site configuration: resolving its
        // TypoScript for USER_INT detection fails, the page info itself survives.
        $this->importCSVDataSet(__DIR__.'/../Fixtures/pages_no_site.csv');

        [$exitCode, $result] = $this->runCommand(['pageId' => '2']);

        self::assertSame(0, $exitCode);
        self::assertSame(2, $result['page']['id']);
        self::assertNull($result['user_int_plugins']);
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
