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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Service;

use KonradMichalik\Typo3AiMate\Service\SiteUrlResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * SiteUrlResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class SiteUrlResolverTest extends TestCase
{
    #[Test]
    public function urlForPageResolvesViaTheSiteRouter(): void
    {
        $uri = self::createStub(UriInterface::class);
        $uri->method('__toString')->willReturn('https://example.test/the-page');
        $router = self::createStub(RouterInterface::class);
        $router->method('generateUri')->willReturn($uri);
        $site = self::createStub(Site::class);
        $site->method('getRouter')->willReturn($router);
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturn($site);

        self::assertSame('https://example.test/the-page', (new SiteUrlResolver($siteFinder))->urlForPage(5, 0));
    }

    #[Test]
    public function urlForPageReturnsNullWhenNoSiteConfigurationExists(): void
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willThrowException(new RuntimeException('no site'));

        self::assertNull((new SiteUrlResolver($siteFinder))->urlForPage(5, 0));
    }

    #[Test]
    public function firstSiteReturnsTheFirstConfiguredSiteRegardlessOfItsBase(): void
    {
        $first = self::createStub(Site::class);
        $second = self::createStub(Site::class);
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['first' => $first, 'second' => $second]);

        self::assertSame($first, (new SiteUrlResolver($siteFinder))->firstSite());
    }

    #[Test]
    public function firstSiteReturnsNullWhenNoSitesAreConfigured(): void
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        self::assertNull((new SiteUrlResolver($siteFinder))->firstSite());
    }

    #[Test]
    public function firstSiteBaseSkipsARelativeBaseInFavourOfAnAbsoluteOne(): void
    {
        $relativeUri = self::createStub(UriInterface::class);
        $relativeUri->method('__toString')->willReturn('/');
        $relativeSite = self::createStub(Site::class);
        $relativeSite->method('getBase')->willReturn($relativeUri);

        $absoluteUri = self::createStub(UriInterface::class);
        $absoluteUri->method('__toString')->willReturn('https://example.test/');
        $absoluteSite = self::createStub(Site::class);
        $absoluteSite->method('getBase')->willReturn($absoluteUri);

        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['relative' => $relativeSite, 'absolute' => $absoluteSite]);

        self::assertSame('https://example.test/', (new SiteUrlResolver($siteFinder))->firstSiteBase());
    }

    #[Test]
    public function firstSiteBaseReturnsNullWhenNoSiteHasAnAbsoluteBase(): void
    {
        $uri = self::createStub(UriInterface::class);
        $uri->method('__toString')->willReturn('/');
        $site = self::createStub(Site::class);
        $site->method('getBase')->willReturn($uri);
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['example' => $site]);

        self::assertNull((new SiteUrlResolver($siteFinder))->firstSiteBase());
    }

    #[Test]
    public function isAllowedHostAcceptsAConfiguredSiteHostCaseInsensitively(): void
    {
        self::assertTrue($this->resolverWithBase('https://Example.test/')->isAllowedHost('https://EXAMPLE.test/some/path'));
    }

    #[Test]
    public function isAllowedHostRejectsAnUnconfiguredHost(): void
    {
        self::assertFalse($this->resolverWithBase('https://example.test/')->isAllowedHost('http://169.254.169.254/latest/meta-data/'));
    }

    #[Test]
    public function isAllowedHostRejectsAUrlWithoutAHost(): void
    {
        self::assertFalse($this->resolverWithBase('https://example.test/')->isAllowedHost('file:///etc/passwd'));
    }

    #[Test]
    public function isAllowedHostRejectsAMatchingHostOnADifferentPort(): void
    {
        self::assertFalse($this->resolverWithBase('https://example.test/')->isAllowedHost('https://example.test:8443/'));
    }

    #[Test]
    public function isAllowedHostRejectsAMatchingHostWithADifferentScheme(): void
    {
        self::assertFalse($this->resolverWithBase('https://example.test/')->isAllowedHost('http://example.test/'));
    }

    #[Test]
    public function isAllowedHostAcceptsTheDefaultPortWhenTheConfiguredBaseOmitsIt(): void
    {
        self::assertTrue($this->resolverWithBase('https://example.test/')->isAllowedHost('https://example.test:443/'));
    }

    #[Test]
    public function siteOriginsReturnsAnEmptyListWhenSitesCannotBeLoaded(): void
    {
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willThrowException(new RuntimeException('sites unavailable'));

        self::assertSame([], (new SiteUrlResolver($siteFinder))->siteOrigins());
    }

    private function resolverWithBase(string $base): SiteUrlResolver
    {
        $uri = self::createStub(UriInterface::class);
        $uri->method('__toString')->willReturn($base);
        $site = self::createStub(Site::class);
        $site->method('getBase')->willReturn($uri);
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['example' => $site]);

        return new SiteUrlResolver($siteFinder);
    }
}
