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

use KonradMichalik\Typo3AiMate\Command\SiteCommand;
use KonradMichalik\Typo3AiMate\Service\SiteUrlResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * SiteCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class SiteCommandTest extends TestCase
{
    #[Test]
    public function describeSiteReportsIdentifierBaseRootPageLanguagesAndErrorHandling(): void
    {
        $site = new Site('main', 1, [
            'base' => 'https://example.test/',
            'languages' => [
                0 => ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
                1 => ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
            ],
            'errorHandling' => [
                ['errorCode' => 404, 'errorHandler' => 'Page', 'errorContentSource' => 't3://page?uid=1'],
            ],
        ]);

        $described = $this->command()->describeSite($site);

        self::assertSame('main', $described['identifier']);
        self::assertSame('https://example.test/', $described['base']);
        self::assertSame(1, $described['rootPageId']);
        self::assertCount(2, $described['languages']);
        self::assertSame(['id' => 0, 'locale' => 'en-US', 'base' => 'https://example.test/', 'title' => 'English'], $described['languages'][0]);
        self::assertSame(['id' => 1, 'locale' => 'de-DE', 'base' => 'https://example.test/de/', 'title' => 'Deutsch'], $described['languages'][1]);
        self::assertSame(
            [['errorCode' => 404, 'errorHandler' => 'Page', 'errorContentSource' => 't3://page?uid=1']],
            $described['errorHandling'],
        );
    }

    #[Test]
    public function describeSiteReturnsAnEmptyListWhenNoErrorHandlingIsConfigured(): void
    {
        $site = new Site('main', 1, ['base' => 'https://example.test/']);

        self::assertSame([], $this->command()->describeSite($site)['errorHandling']);
    }

    #[Test]
    public function executeListsAllSites(): void
    {
        $siteA = new Site('a', 1, ['base' => 'https://a.test/']);
        $siteB = new Site('b', 2, ['base' => 'https://b.test/']);
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['a' => $siteA, 'b' => $siteB]);

        $tester = new CommandTester($this->command($siteFinder));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        $sites = $result['sites'];
        self::assertIsArray($sites);
        self::assertCount(2, $sites);
        $first = $sites[0];
        self::assertIsArray($first);
        self::assertSame('a', $first['identifier']);
    }

    #[Test]
    public function executeFailsWithAStructuredErrorWhenSiteConfigurationCannotBeLoaded(): void
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willThrowException(new RuntimeException('sites unavailable'));

        $tester = new CommandTester($this->command($siteFinder));
        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('Could not load site configuration.', $result['error']);
    }

    #[Test]
    public function executeReturnsOneSiteByIdentifier(): void
    {
        $site = new Site('main', 1, ['base' => 'https://example.test/']);
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getSiteByIdentifier')->willReturn($site);

        $tester = new CommandTester($this->command($siteFinder));
        $exitCode = $tester->execute(['--identifier' => 'main']);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        $describedSite = $result['site'];
        self::assertIsArray($describedSite);
        self::assertSame('main', $describedSite['identifier']);
    }

    #[Test]
    public function executeFailsForAnUnknownSiteIdentifier(): void
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getSiteByIdentifier')->willThrowException(new RuntimeException('unknown'));

        $tester = new CommandTester($this->command($siteFinder));
        $exitCode = $tester->execute(['--identifier' => 'ghost']);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('Unknown site identifier "ghost".', $result['error']);
    }

    #[Test]
    public function executeFailsWithAnActionableErrorForAPageWithoutASite(): void
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willThrowException(new RuntimeException('no site'));

        $tester = new CommandTester($this->command($siteFinder, new SiteUrlResolver($siteFinder)));
        $exitCode = $tester->execute(['--pageId' => '999']);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertIsString($result['error']);
        self::assertStringContainsString('no site configuration', $result['error']);
        self::assertStringContainsString('list configured sites', $result['error']);
    }

    #[Test]
    public function executeFailsWhenNoSiteIsConfiguredForTheFallback(): void
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        $tester = new CommandTester($this->command($siteFinder, new SiteUrlResolver($siteFinder)));
        $exitCode = $tester->execute(['--pageId' => '0']);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('No site is configured.', $result['error']);
    }

    private function command(?SiteFinder $siteFinder = null, ?SiteUrlResolver $siteUrlResolver = null): SiteCommand
    {
        $siteFinder ??= self::createStub(SiteFinder::class);

        return new SiteCommand(
            $siteFinder,
            $siteUrlResolver ?? new SiteUrlResolver($siteFinder),
            self::createStub(UriBuilder::class),
        );
    }
}
