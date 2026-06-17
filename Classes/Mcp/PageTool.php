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

    #[McpTool(name: 'typo3-page', title: 'TYPO3 Page Composition', description: 'Page composition (content elements incl. CType/plugin, backend layout) plus cache signals and USER_INT plugins. Expand a profile page.id.')]
    public function info(?int $pageId = null, ?string $url = null): string
    {
        $arguments = null !== $pageId ? [$pageId] : [];
        $options = null !== $url && '' !== $url ? ['url' => $url] : [];

        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:page:info', $arguments, $options));
    }
}
