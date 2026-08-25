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
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * RenderPageTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class RenderPageTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param int|null    $pageId   Page UID to render; resolved to its speaking URL via the site config. Provide exactly one of pageId or url.
     * @param string|null $url      Explicit URL to render instead of a UID. Provide exactly one of pageId or url.
     * @param int         $language site language id to render in (0 = default language)
     */
    // readOnlyHint: false — unlike every other tool here, this issues a real HTTP request
    // (performRequest() in the wrapped command), which writes to caches and logs as a side
    // effect. openWorldHint: true because the result depends on a running webserver rather
    // than on local TYPO3 state.
    #[McpTool(
        name: 'typo3-render-page',
        title: 'TYPO3 Render Page',
        description: 'Render a frontend page via an internal HTTP request (no external curl/Playwright needed) so runtime notices — deprecations especially — actually fire, then return the HTTP status plus the log entries written during that request. Pass a pageId (resolved to its speaking URL via the site config) or an explicit url; optional language id. Requires a running webserver (e.g. DDEV). Pair with typo3-deprecations afterwards to see the grouped notices with their own-code origins. Need just the URL, without the request/side effects? Use typo3-site instead — it shares this tool\'s URL resolution and also reports site identifiers, root page ids and languages.',
        annotations: new ToolAnnotations(readOnlyHint: false, openWorldHint: true),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'The URL that was actually requested.'],
                'pageId' => ['type' => ['integer', 'null'], 'description' => 'Echoed back page UID, when one was given.'],
                'status' => ['type' => 'integer', 'description' => 'HTTP status code returned by the request. 0 if the request itself failed — see requestError.'],
                'durationMs' => ['type' => 'integer', 'description' => 'Wall-clock time of the request in milliseconds.'],
                'bytes' => ['type' => 'integer', 'description' => 'Response body size in bytes.'],
                'logs' => [
                    'type' => 'object',
                    'description' => 'Log entries written during the request window.',
                    'properties' => [
                        'totalMatched' => ['type' => 'integer', 'description' => 'Total log entries written during the request, before deduplication.'],
                        'distinct' => ['type' => 'integer', 'description' => 'Number of distinct (deduplicated) log messages.'],
                        'entries' => ['type' => 'array', 'description' => 'The deduplicated, grouped log entries.'],
                    ],
                ],
                'requestError' => ['type' => 'string', 'description' => 'Present only when the HTTP request itself failed (e.g. connection refused) — status is 0 in that case. Distinct from unsupported: a requestError means a URL was resolved and requested but the HTTP call failed.'],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if no URL could be resolved to render at all — no pageId/url given, the host is not among the configured site bases, or no site configuration matches. Distinct from requestError, where a URL was resolved but the request itself failed.'],
                'reason' => ['type' => 'string', 'description' => 'Present only when unsupported is true: why no URL could be resolved.'],
            ],
        ],
    )]
    public function render(?int $pageId = null, ?string $url = null, int $language = 0): CallToolResult
    {
        $arguments = null !== $pageId ? [$pageId] : [];

        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:fe:render', $arguments, $this->options([
            'url' => $url,
            'language' => $language,
        ])));
    }

    /**
     * @param array<string, scalar|null> $options
     *
     * @return array<string, scalar>
     */
    private function options(array $options): array
    {
        return array_filter($options, static fn (mixed $value): bool => null !== $value && '' !== $value);
    }
}
