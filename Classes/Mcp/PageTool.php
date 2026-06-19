<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <km@move-elevator.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Mcp;

use KonradMichalik\Typo3AiMate\Mate\Typo3CliRunner;
use Mcp\Capability\Attribute\McpTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * PageTool.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final readonly class PageTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param int|null    $pageId Page UID to inspect — typically the page.id reported by a profiler summary. Provide exactly one of pageId or url.
     * @param string|null $url    Speaking URL to resolve to a page instead of a UID. Provide exactly one of pageId or url.
     */
    #[McpTool(name: 'typo3-page', title: 'TYPO3 Page Composition', description: 'Page composition (content elements incl. CType/plugin, backend layout) plus cache signals and USER_INT plugins. Expand the page.id reported by the profiler tools to see what rendered on a slow page.')]
    public function info(?int $pageId = null, ?string $url = null): string
    {
        $arguments = null !== $pageId ? [$pageId] : [];
        $options = null !== $url && '' !== $url ? ['url' => $url] : [];

        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:page:info', $arguments, $options));
    }
}
