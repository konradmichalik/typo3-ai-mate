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

namespace KonradMichalik\Typo3AiMate\Service;

use KonradMichalik\Typo3AiMate\Support\{Cast, SiteBases};
use Psr\Http\Message\UriInterface;
use Throwable;
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Http\{ServerRequest, Uri};
use TYPO3\CMS\Core\Routing\{PageArguments, SiteMatcher, SiteRouteResult};
use TYPO3\CMS\Core\Site\Entity\{Site, SiteLanguage};
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function array_values;
use function parse_str;
use function sprintf;
use function str_starts_with;

/**
 * RouteResolver.
 *
 * Resolves a frontend URL the way the frontend does: redirect match first
 * (EXT:redirects), then site and language through the SiteMatcher, then the
 * page and its route enhancer arguments through the site's PageRouter.
 *
 * @phpstan-type Route array{url: string, outcome: string, redirectsAvailable: bool, redirect: array<string, mixed>|null, site: array{identifier: string, base: string, rootPageId: int}|null, language: array{id: int, locale: string, base: string}|null, page: array{id: int, title: string, slug: string, doktype: int, type: string}|null, routeArguments?: array<mixed>, staticArguments?: array<mixed>, dynamicArguments?: array<mixed>, queryArguments?: array<mixed>, reason?: string, closestBase?: array{site: string, base: string}|null}
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class RouteResolver
{
    public function __construct(
        private SiteFinder $siteFinder,
        private SiteMatcher $siteMatcher,
        private ConnectionPool $connectionPool,
        private RedirectMatcher $redirectMatcher,
    ) {}

    public function resolvePageId(string $url): ?int
    {
        return $this->resolve($url)['page']['id'] ?? null;
    }

    /**
     * @return Route
     */
    public function resolve(string $url): array
    {
        try {
            $sites = array_values($this->siteFinder->getAllSites());
        } catch (Throwable) {
            $sites = [];
        }

        $best = null;
        foreach ([] !== $sites ? $this->candidateUris($url, $sites) : [] as $uri) {
            $result = $this->resolveUri($uri);
            if ('no-match' !== $result['outcome']) {
                return $result;
            }
            if (null === $best || (null === $best['site'] && null !== $result['site'])) {
                $best = $result;
            }
        }

        $route = $best ?? $this->describe($url, null, null, null);
        $route['reason'] = $this->noMatchReason($route, [] === $sites);
        $route['closestBase'] = SiteBases::closest($route['url'], $sites);

        return $route;
    }

    /**
     * @return Route
     */
    private function resolveUri(UriInterface $uri): array
    {
        $redirect = $this->redirectMatcher->match($uri);
        $siteRoute = $this->matchSite($uri);
        $site = $siteRoute?->getSite();
        if (null === $siteRoute || !$site instanceof Site) {
            return $this->describe((string) $uri, $redirect, null, null);
        }

        $language = $siteRoute->getLanguage();
        $result = $this->describe((string) $uri, $redirect, $site, $language);
        $pageArguments = null !== $language ? $this->matchPage($uri, $site, $siteRoute) : null;
        if (null !== $pageArguments) {
            $result['outcome'] = null !== $redirect ? 'redirect' : 'page';
            $result['page'] = $this->describePage($pageArguments, $language);
            $result['routeArguments'] = $pageArguments->getRouteArguments();
            $result['staticArguments'] = $pageArguments->getStaticArguments();
            $result['dynamicArguments'] = $pageArguments->getDynamicArguments();
            $result['queryArguments'] = $pageArguments->getQueryArguments();
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|null $redirect
     *
     * @return Route
     */
    private function describe(string $url, ?array $redirect, ?Site $site, ?SiteLanguage $language): array
    {
        return [
            'url' => $url,
            'outcome' => null !== $redirect ? 'redirect' : 'no-match',
            'redirectsAvailable' => $this->redirectMatcher->isAvailable(),
            'redirect' => $redirect,
            'site' => null !== $site ? [
                'identifier' => $site->getIdentifier(),
                'base' => (string) $site->getBase(),
                'rootPageId' => $site->getRootPageId(),
            ] : null,
            'language' => null !== $language ? [
                'id' => $language->getLanguageId(),
                'locale' => (string) $language->getLocale(),
                'base' => (string) $language->getBase(),
            ] : null,
            'page' => null,
        ];
    }

    /**
     * A relative URL carries no host, so it is tried against the origin of
     * every configured site and language base, in configuration order.
     *
     * @param list<Site> $sites
     *
     * @return list<UriInterface>
     */
    private function candidateUris(string $url, array $sites): array
    {
        try {
            $uri = new Uri($url);
        } catch (Throwable) {
            return [];
        }
        if ('' !== $uri->getHost()) {
            return [$uri];
        }

        $path = str_starts_with($uri->getPath(), '/') ? $uri->getPath() : '/'.$uri->getPath();
        $candidates = [];
        foreach (SiteBases::origins($sites) as $origin) {
            $candidates[] = (new Uri($origin))->withPath($path)->withQuery($uri->getQuery());
        }

        return $candidates;
    }

    private function matchSite(UriInterface $uri): ?SiteRouteResult
    {
        try {
            $result = $this->siteMatcher->matchRequest($this->requestFor($uri));
            // @codeCoverageIgnoreStart
        } catch (Throwable) {
            // Defensive: the SiteMatcher answers an unknown host with a NullSite.
            return null;
        }
        // @codeCoverageIgnoreEnd

        return $result instanceof SiteRouteResult ? $result : null;
    }

    private function matchPage(UriInterface $uri, Site $site, SiteRouteResult $siteRoute): ?PageArguments
    {
        try {
            $result = $site->getRouter()->matchRequest($this->requestFor($uri), $siteRoute);
        } catch (Throwable) {
            return null;
        }

        return $result instanceof PageArguments ? $result : null;
    }

    private function requestFor(UriInterface $uri): ServerRequest
    {
        parse_str($uri->getQuery(), $query);

        return (new ServerRequest($uri, 'GET', 'php://temp'))->withQueryParams($query);
    }

    /**
     * @return array{id: int, title: string, slug: string, doktype: int, type: string}
     */
    private function describePage(PageArguments $pageArguments, SiteLanguage $language): array
    {
        $pageId = $pageArguments->getPageId();
        $row = $this->fetchPageRow($pageId, $language->getLanguageId()) ?? $this->fetchPageRow($pageId, 0) ?? [];

        return [
            'id' => $pageId,
            'title' => Cast::string($row['title'] ?? ''),
            'slug' => Cast::string($row['slug'] ?? ''),
            'doktype' => Cast::int($row['doktype'] ?? 0),
            'type' => $pageArguments->getPageType(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchPageRow(int $pageId, int $languageId): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $idField = 0 === $languageId ? 'uid' : 'l10n_parent';

        /** @var array<string, mixed>|false $row */
        $row = $queryBuilder
            ->select('title', 'slug', 'doktype')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq($idField, $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return false === $row ? null : $row;
    }

    /**
     * @param Route $route
     */
    private function noMatchReason(array $route, bool $noSites): string
    {
        return match (true) {
            $noSites => 'No site is configured, or the site configuration could not be loaded.',
            null === $route['site'] => 'No configured site base matches this URL.',
            null === $route['language'] => sprintf('Site "%s" matches, but none of its language bases does.', $route['site']['identifier']),
            default => 'Site and language match, but no visible page slug or route enhancer matches the path.',
        };
    }
}
