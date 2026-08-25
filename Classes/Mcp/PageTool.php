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
 * PageTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PageTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param int|null    $pageId Page UID to inspect — typically the page.id reported by a profiler summary. Provide exactly one of pageId or url.
     * @param string|null $url    Speaking URL to resolve to a page instead of a UID. Provide exactly one of pageId or url.
     */
    #[McpTool(
        name: 'typo3-page',
        title: 'TYPO3 Page Composition',
        description: 'Page composition (content elements incl. CType/plugin, backend layout) plus cache signals and USER_INT plugins. Expand the page.id reported by the profiler tools to see what rendered on a slow page.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'page' => ['type' => 'object', 'description' => '{id, title, backend_layout}.'],
                'cache' => ['type' => 'object', 'description' => '{cache_timeout}: pages.no_cache was removed in TYPO3 v12, only cache_timeout remains.'],
                'content_elements' => [
                    'type' => 'array',
                    'description' => 'One entry per non-deleted content element on the page, in colPos/sorting order. Empty is a real answer: the page has no content elements.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'uid' => ['type' => 'integer'],
                            'colPos' => ['type' => 'integer'],
                            'CType' => ['type' => 'string'],
                            'plugin' => ['type' => ['string', 'null'], 'description' => 'Classic plugin signature (list_type), or null when the element is not a plugin / on TYPO3 v14 where list_type no longer exists.'],
                            'header' => ['type' => 'string'],
                            'hidden' => ['type' => 'boolean'],
                        ],
                    ],
                ],
                'user_int_plugins' => [
                    'type' => ['array', 'null'],
                    'items' => ['type' => 'string'],
                    'description' => 'CType/plugin signatures on this page that render as USER_INT (uncached) — the most common cause of slow pages. Empty array means none do (a real answer); null means the page TypoScript could not be resolved, so the question could not be answered at all.',
                ],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all: no resolvable page id, the page was not found, or the console was unreachable.'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function info(?int $pageId = null, ?string $url = null): CallToolResult
    {
        $arguments = null !== $pageId ? [$pageId] : [];
        $options = null !== $url && '' !== $url ? ['url' => $url] : [];

        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:page:info', $arguments, $options));
    }
}
