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
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * SiteCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class SiteCommandTest extends FunctionalTestCase
{
    // EXT:install provides LateBootService (autowired by UpgradeWizardsCommand),
    // which the extension's service definitions require to compile.
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Fixtures/pages_basic.csv');

        $sitePath = Environment::getConfigPath().'/sites/main';
        GeneralUtility::mkdir_deep($sitePath);
        file_put_contents($sitePath.'/config.yaml', Yaml::dump([
            'rootPageId' => 1,
            'base' => 'https://example.test/',
            'languages' => [
                [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'navigationTitle' => 'English',
                    'flag' => 'us',
                ],
                [
                    'title' => 'Deutsch',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => '/de/',
                    'locale' => 'de_DE.UTF-8',
                    'navigationTitle' => 'Deutsch',
                    'fallbackType' => 'fallback',
                    'fallbacks' => '0',
                    'flag' => 'de',
                ],
            ],
        ]));
    }

    #[Test]
    public function listsTheConfiguredSiteWithLanguagesAndBase(): void
    {
        [$exitCode, $result] = $this->runCommand([]);

        self::assertSame(0, $exitCode);
        $sites = $result['sites'];
        self::assertIsArray($sites);
        self::assertCount(1, $sites);
        $site = $sites[0];
        self::assertIsArray($site);
        self::assertSame('main', $site['identifier']);
        self::assertSame('https://example.test/', $site['base']);
        self::assertSame(1, $site['rootPageId']);

        $languages = $site['languages'];
        self::assertIsArray($languages);
        self::assertCount(2, $languages);
    }

    #[Test]
    public function resolvesOneSiteByIdentifier(): void
    {
        [$exitCode, $result] = $this->runCommand(['--identifier' => 'main']);

        self::assertSame(0, $exitCode);
        $site = $result['site'];
        self::assertIsArray($site);
        self::assertSame('main', $site['identifier']);
    }

    #[Test]
    public function failsForAnUnknownSiteIdentifier(): void
    {
        [$exitCode, $result] = $this->runCommand(['--identifier' => 'ghost']);

        self::assertSame(1, $exitCode);
        self::assertSame('Unknown site identifier "ghost".', $result['error']);
    }

    #[Test]
    public function resolvesTheFrontendAndBackendUrlForAPage(): void
    {
        [$exitCode, $result] = $this->runCommand(['--pageId' => '1']);

        self::assertSame(0, $exitCode);
        self::assertSame(1, $result['pageId']);
        self::assertSame(0, $result['languageId']);
        self::assertSame('https://example.test/', $result['frontendUrl']);

        $backendUrl = $result['backendUrl'];
        self::assertIsString($backendUrl);
        self::assertStringStartsWith('https://example.test/typo3/module/web/layout', $backendUrl);
        self::assertStringContainsString('id=1', $backendUrl);
    }

    #[Test]
    public function resolvesTheRootPageOfTheFirstSiteWhenPageIdIsZero(): void
    {
        [$exitCode, $result] = $this->runCommand(['--pageId' => '0']);

        self::assertSame(0, $exitCode);
        self::assertSame(1, $result['pageId']);
        self::assertSame('https://example.test/', $result['frontendUrl']);
    }

    #[Test]
    public function failsWithAnActionableErrorForAPageWithoutASite(): void
    {
        [$exitCode, $result] = $this->runCommand(['--pageId' => '999999']);

        self::assertSame(1, $exitCode);
        self::assertIsString($result['error']);
        self::assertStringContainsString('no site configuration', $result['error']);
        self::assertStringContainsString('list configured sites', $result['error']);
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:site:dump');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
