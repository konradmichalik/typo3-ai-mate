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

use Throwable;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

use function in_array;
use function is_int;
use function is_string;
use function parse_url;
use function sprintf;
use function str_contains;
use function strtolower;

/**
 * SiteUrlResolver.
 *
 * Site-configuration-derived URL resolution shared by `typo3-render-page` and
 * `typo3-site`: resolving a page id to its speaking frontend URL, and the
 * SSRF host allowlist that guards an explicit URL against the configured
 * sites.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class SiteUrlResolver
{
    public function __construct(private SiteFinder $siteFinder) {}

    public function urlForPage(int $pageId, int $language): ?string
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);

            return (string) $site->getRouter()->generateUri($pageId, ['_language' => $language]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The first configured site, in `getAllSites()` order, or null if none
     * are configured (or configuration cannot be loaded).
     */
    public function firstSite(): ?Site
    {
        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                return $site;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * The base of the first site that has an absolute one. Skips a
     * relative-only base (Guzzle rejects a scheme-less URL like "/"), so this
     * is deliberately not just {@see firstSite()}'s base.
     */
    public function firstSiteBase(): ?string
    {
        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $base = (string) $site->getBase();
                if (str_contains($base, '://')) {
                    return $base;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Whether the URL's origin (scheme, host and effective port) matches one
     * of the configured site bases. Host alone is not enough: a host match on
     * a different scheme or port would still let this tool reach an
     * unconfigured service on the same machine (SSRF), e.g. a container
     * management API listening on another port of the same hostname.
     */
    public function isAllowedHost(string $url): bool
    {
        $origin = self::originOf($url);

        return null !== $origin && in_array($origin, $this->siteOrigins(), true);
    }

    /**
     * @return list<string>
     */
    public function siteOrigins(): array
    {
        $origins = [];
        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $origin = self::originOf((string) $site->getBase());
                if (null !== $origin) {
                    $origins[] = $origin;
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $origins;
    }

    /**
     * Normalized "scheme://host:port" (the scheme's default port filled in
     * when the URL omits one), or null if the URL has no scheme/host to
     * build one from.
     */
    private static function originOf(string $url): ?string
    {
        $scheme = parse_url($url, \PHP_URL_SCHEME);
        $host = parse_url($url, \PHP_URL_HOST);
        if (!is_string($scheme) || '' === $scheme || !is_string($host) || '' === $host) {
            return null;
        }

        $port = parse_url($url, \PHP_URL_PORT);
        if (!is_int($port)) {
            $port = 'https' === strtolower($scheme) ? 443 : 80;
        }

        return sprintf('%s://%s:%d', strtolower($scheme), strtolower($host), $port);
    }
}
