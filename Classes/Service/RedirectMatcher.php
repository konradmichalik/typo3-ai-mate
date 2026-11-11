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

use KonradMichalik\Typo3AiMate\Support\Cast;
use Psr\Http\Message\UriInterface;
use Throwable;
use TYPO3\CMS\Redirects\Service\RedirectService;

/**
 * RedirectMatcher.
 *
 * Matches a URL against the sys_redirect entries with the same host, path and
 * query split the EXT:redirects frontend middleware uses.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class RedirectMatcher
{
    /**
     * @param object|null $redirectService RedirectService of EXT:redirects, wired in Services.yaml as an optional reference because the extension is optional
     */
    public function __construct(private ?object $redirectService = null) {}

    public function isAvailable(): bool
    {
        return $this->redirectService instanceof RedirectService;
    }

    /**
     * @return array{uid: int, sourceHost: string, sourcePath: string, isRegexp: bool, target: string, statusCode: int}|null
     */
    public function match(UriInterface $uri): ?array
    {
        if (!$this->redirectService instanceof RedirectService) {
            return null;
        }

        $port = $uri->getPort();
        try {
            $row = $this->redirectService->matchRedirect(
                $uri->getHost().(null !== $port ? ':'.$port : ''),
                $uri->getPath(),
                $uri->getQuery(),
            );
            // @codeCoverageIgnoreStart
        } catch (Throwable) {
            // Defensive: a broken redirect cache must not hide the page match.
            return null;
        }
        // @codeCoverageIgnoreEnd

        return null !== $row ? [
            'uid' => Cast::int($row['uid'] ?? 0),
            'sourceHost' => Cast::string($row['source_host'] ?? ''),
            'sourcePath' => Cast::string($row['source_path'] ?? ''),
            'isRegexp' => (bool) ($row['is_regexp'] ?? false),
            'target' => Cast::string($row['target'] ?? ''),
            'statusCode' => Cast::int($row['target_statuscode'] ?? 0),
        ] : null;
    }
}
