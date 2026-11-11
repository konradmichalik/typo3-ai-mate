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
 * RouteCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RouteCommandTest extends FunctionalTestCase
{
    // EXT:install provides LateBootService (autowired by UpgradeWizardsCommand);
    // EXT:redirects is the source of the redirect match.
    protected array $coreExtensionsToLoad = [
        'install',
        'redirects',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Fixtures/pages_route.csv');
        $this->importCSVDataSet(__DIR__.'/../Fixtures/sys_redirect_route.csv');
        self::writeSiteConfiguration();
    }

    public static function writeSiteConfiguration(): void
    {
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
                ],
                [
                    'title' => 'Deutsch',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => '/de/',
                    'locale' => 'de_DE.UTF-8',
                    'fallbackType' => 'strict',
                ],
            ],
            'routeEnhancers' => [
                'News' => [
                    'type' => 'Extbase',
                    'limitToPages' => [4],
                    'extension' => 'News',
                    'plugin' => 'Pi1',
                    'routes' => [
                        ['routePath' => '/{news_id}', '_controller' => 'News::detail', '_arguments' => ['news_id' => 'news']],
                    ],
                    'defaultController' => 'News::list',
                    'requirements' => ['news_id' => '\d+'],
                    'aspects' => [
                        'news_id' => ['type' => 'StaticRangeMapper', 'start' => '1', 'end' => '100'],
                    ],
                ],
            ],
        ], 10));
    }

    #[Test]
    public function resolvesAnAbsoluteUrlToTheActualSubpage(): void
    {
        [$exitCode, $result] = $this->runCommand('https://example.test/products/widget');

        self::assertSame(0, $exitCode);
        self::assertSame('page', $result['outcome']);
        self::assertSame(['identifier' => 'main', 'base' => 'https://example.test/', 'rootPageId' => 1], $result['site']);
        self::assertSame(0, $result['language']['id']);
        self::assertSame(['id' => 3, 'title' => 'Widget', 'slug' => '/products/widget', 'doktype' => 1, 'type' => '0'], $result['page']);
    }

    #[Test]
    public function resolvesARelativeUrlAgainstTheConfiguredSites(): void
    {
        [$exitCode, $result] = $this->runCommand('/products/widget');

        self::assertSame(0, $exitCode);
        self::assertSame('page', $result['outcome']);
        self::assertSame('https://example.test/products/widget', $result['url']);
        self::assertSame(3, $result['page']['id']);
    }

    #[Test]
    public function resolvesATranslatedSlugToTheDefaultLanguagePageWithItsLocalizedSlug(): void
    {
        [$exitCode, $result] = $this->runCommand('https://example.test/de/produkte');

        self::assertSame(0, $exitCode);
        self::assertSame(1, $result['language']['id']);
        self::assertSame(2, $result['page']['id']);
        self::assertSame('/produkte', $result['page']['slug']);
    }

    #[Test]
    public function returnsTheRouteEnhancerArgumentsOfAnExtbaseDetailUrl(): void
    {
        [$exitCode, $result] = $this->runCommand('https://example.test/news/5');

        self::assertSame(0, $exitCode);
        self::assertSame(4, $result['page']['id']);
        $expected = ['tx_news_pi1' => ['action' => 'detail', 'controller' => 'News', 'news' => '5']];
        self::assertSame($expected, $result['routeArguments']);
        self::assertSame($expected, $result['staticArguments']);
        self::assertSame([], $result['dynamicArguments']);
    }

    #[Test]
    public function reportsAMatchingRedirectBeforeThePageMatch(): void
    {
        [$exitCode, $result] = $this->runCommand('https://example.test/old-products');

        self::assertSame(0, $exitCode);
        self::assertSame('redirect', $result['outcome']);
        self::assertTrue($result['redirectsAvailable']);
        self::assertSame([
            'uid' => 1,
            'sourceHost' => '*',
            'sourcePath' => '/old-products',
            'isRegexp' => false,
            'target' => 't3://page?uid=2',
            'statusCode' => 301,
        ], $result['redirect']);
    }

    #[Test]
    public function doesNotLetARedirectToTheRequestedUrlWinOverThePage(): void
    {
        [, $result] = $this->runCommand('https://example.test/products');

        self::assertSame('page', $result['outcome']);
        self::assertIsArray($result['redirect']);
        self::assertArrayHasKey('ignored', $result['redirect']);
        self::assertIsArray($result['page']);
        self::assertSame(2, $result['page']['id']);
    }

    #[Test]
    public function answersNoMatchWithTheClosestSiteBaseForAnUnknownHost(): void
    {
        [$exitCode, $result] = $this->runCommand('https://unknown.example/products');

        self::assertSame(0, $exitCode);
        self::assertSame('no-match', $result['outcome']);
        self::assertNull($result['site']);
        self::assertIsString($result['reason']);
        self::assertSame(['site' => 'main', 'base' => 'https://example.test/'], $result['closestBase']);
    }

    #[Test]
    public function answersNoMatchWhenNoPageSlugMatchesThePath(): void
    {
        [$exitCode, $result] = $this->runCommand('https://example.test/de/does-not-exist');

        self::assertSame(0, $exitCode);
        self::assertSame('no-match', $result['outcome']);
        self::assertSame('main', $result['site']['identifier']);
        self::assertSame(1, $result['language']['id']);
        self::assertNull($result['page']);
        self::assertSame(['site' => 'main', 'base' => 'https://example.test/de/'], $result['closestBase']);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(string $url): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:route:resolve');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['url' => $url]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
