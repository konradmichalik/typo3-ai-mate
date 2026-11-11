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

namespace KonradMichalik\Typo3AiMate\Mcp;

use KonradMichalik\Typo3AiMate\Mate\{ToolResult, Typo3CliRunner};
use Symfony\AI\Mate\Attribute\MateTool;

/**
 * RouteTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RouteTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string $url absolute frontend URL, or a path such as /products/widget that is tried against every configured site base
     */
    #[MateTool(
        name: 'typo3-route',
        title: 'TYPO3 URL Routing',
        description: 'Which page a frontend URL hits, and why: resolved through the real site router, not a prefix match. Returns the matched site, language and page (id, slug, doktype, page type) plus the route enhancer arguments (e.g. an Extbase plugin namespace with controller, action and record uid) split into static, dynamic and query arguments. A matching EXT:redirects entry (source, target, status code) is checked first, as the frontend does, and outcome=redirect means it wins. A miss answers outcome=no-match with a reason and the closest configured site base, not an empty result. Read-only: no request is sent.',
    )]
    public function resolve(string $url): string
    {
        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:route:resolve', [$url]));
    }
}
