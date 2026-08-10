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
use function is_string;
use function parse_url;
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
     * Whether the URL's host is one of the configured site hosts. Blocks a
     * tool from being turned into a generic fetcher against internal/cloud-
     * metadata endpoints (SSRF).
     */
    public function isAllowedHost(string $url): bool
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!is_string($host) || '' === $host) {
            return false;
        }

        return in_array(strtolower($host), $this->siteHosts(), true);
    }

    /**
     * @return list<string>
     */
    public function siteHosts(): array
    {
        $hosts = [];
        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $host = parse_url((string) $site->getBase(), \PHP_URL_HOST);
                if (is_string($host) && '' !== $host) {
                    $hosts[] = strtolower($host);
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $hosts;
    }
}
