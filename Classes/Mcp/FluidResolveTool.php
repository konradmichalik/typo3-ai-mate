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
 * FluidResolveTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class FluidResolveTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param int         $pageId   page UID whose resolved TypoScript provides the root paths
     * @param string      $plugin   TypoScript path to the view config, e.g. plugin.tx_news_pi1 or page.10
     * @param string|null $template template name to resolve to a file, e.g. News/List
     * @param string|null $partial  partial name to resolve to a file
     * @param string|null $layout   layout name to resolve to a file
     * @param string      $format   file format (default html)
     */
    #[MateTool(
        name: 'typo3-fluid-resolve',
        title: 'TYPO3 Fluid Path Resolution',
        description: 'Which physical Fluid file wins for a template/partial/layout name, given the merged templateRootPaths/partialRootPaths/layoutRootPaths override chain (highest numeric key first). Returns the ordered candidate directories with exists flags plus the resolved file — use it to debug why an override does not take effect. A plugin path with no view configuration answers with viewPathFound=false plus the view paths that do have one, so a wrong guess costs one call, not several.',
    )]
    public function resolve(int $pageId, string $plugin, ?string $template = null, ?string $partial = null, ?string $layout = null, string $format = 'html'): string
    {
        $options = ['plugin' => $plugin];
        if (null !== $template && '' !== $template) {
            $options['template'] = $template;
        }
        if (null !== $partial && '' !== $partial) {
            $options['partial'] = $partial;
        }
        if (null !== $layout && '' !== $layout) {
            $options['layout'] = $layout;
        }
        $options['format'] = $format;

        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:fluid:resolve', [$pageId], $options));
    }
}
