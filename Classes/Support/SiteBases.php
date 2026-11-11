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

namespace KonradMichalik\Typo3AiMate\Support;

use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Site\Entity\{Site, SiteLanguage};

use function array_keys;
use function array_map;
use function array_values;
use function min;
use function parse_url;
use function str_contains;
use function strlen;

/**
 * SiteBases.
 *
 * The site and language bases of the configured sites, as origins to try a
 * host-less path against and as the "closest base" hint of a routing miss.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class SiteBases
{
    // A host-less base matches any host, so any origin reaches it.
    private const HOSTLESS_ORIGIN = 'http://localhost';

    /**
     * @param list<Site> $sites
     *
     * @return list<string>
     */
    public static function origins(array $sites): array
    {
        $origins = [];
        foreach ($sites as $site) {
            foreach (self::basesOf($site) as $base) {
                $origin = '' !== $base->getHost()
                    ? (string) $base->withPath('')->withQuery('')->withFragment('')
                    : self::HOSTLESS_ORIGIN;
                $origins[$origin] = true;
            }
        }

        return array_keys($origins);
    }

    /**
     * The base sharing the longest prefix with the URL. A host-less base is
     * compared against the URL's path only.
     *
     * @param list<Site> $sites
     *
     * @return array{site: string, base: string}|null
     */
    public static function closest(string $url, array $sites): ?array
    {
        $path = Cast::string(parse_url($url, \PHP_URL_PATH));
        $closest = null;
        $closestLength = -1;
        foreach ($sites as $site) {
            foreach (self::basesOf($site) as $base) {
                $baseString = (string) $base;
                $length = self::commonPrefixLength(str_contains($baseString, '://') ? $url : $path, $baseString);
                if ($length > $closestLength) {
                    $closest = ['site' => $site->getIdentifier(), 'base' => $baseString];
                    $closestLength = $length;
                }
            }
        }

        return $closest;
    }

    /**
     * @return list<UriInterface>
     */
    private static function basesOf(Site $site): array
    {
        return [
            $site->getBase(),
            ...array_values(array_map(static fn (SiteLanguage $language): UriInterface => $language->getBase(), $site->getAllLanguages())),
        ];
    }

    private static function commonPrefixLength(string $a, string $b): int
    {
        $max = min(strlen($a), strlen($b));
        $length = 0;
        while ($length < $max && $a[$length] === $b[$length]) {
            ++$length;
        }

        return $length;
    }
}
