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

namespace KonradMichalik\Typo3AiMate\Mate;

use Symfony\Component\Yaml\Yaml;
use Throwable;

use function is_array;
use function is_string;

/**
 * SiteHostsProvider.
 *
 * Reads the `base` of every `config/sites/{identifier}/config.yaml` directly, without
 * booting TYPO3 (the Mate process has no SiteFinder). Advisory only, for
 * describing typo3-render-page's SSRF guard in its tool description — the
 * guard itself stays in RenderPageCommand::isAllowedHost(), sourced from the
 * booted SiteFinder, which is the actual security boundary.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class SiteHostsProvider
{
    public function __construct(private string $rootDir) {}

    /**
     * @return list<string>
     */
    public function hosts(): array
    {
        $hosts = [];

        foreach (glob($this->rootDir.'/config/sites/*/config.yaml') ?: [] as $file) {
            $host = $this->hostFromConfigFile($file);
            if (null !== $host) {
                $hosts[] = $host;
            }
        }

        sort($hosts);

        return array_values(array_unique($hosts));
    }

    private function hostFromConfigFile(string $file): ?string
    {
        try {
            $config = Yaml::parseFile($file);
        } catch (Throwable) {
            return null;
        }

        $base = is_array($config) ? $config['base'] ?? null : null;
        if (!is_string($base) || '' === $base) {
            return null;
        }

        $host = parse_url($base, \PHP_URL_HOST);

        return is_string($host) && '' !== $host ? strtolower($host) : null;
    }
}
