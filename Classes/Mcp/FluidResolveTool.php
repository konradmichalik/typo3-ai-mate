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
    #[McpTool(
        name: 'typo3-fluid-resolve',
        title: 'TYPO3 Fluid Path Resolution',
        description: 'Which physical Fluid file wins for a template/partial/layout name, given the merged templateRootPaths/partialRootPaths/layoutRootPaths override chain (highest numeric key first). Returns the ordered candidate directories with exists flags plus the resolved file — use it to debug why an override does not take effect. A plugin path with no view configuration answers with viewPathFound=false plus the view paths that do have one, so a wrong guess costs one call, not several.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'viewPath' => ['type' => 'string', 'description' => 'The requested plugin TypoScript path, echoed back.'],
                'viewPathFound' => ['type' => 'boolean', 'description' => 'Whether plugin declares any templateRootPaths/partialRootPaths/layoutRootPaths at all. false is the answer, not an empty result.'],
                'candidateCount' => ['type' => 'integer', 'description' => 'Present only when viewPathFound is false: number of view paths in the resolved TypoScript that do declare root paths.'],
                'candidates' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Present only when viewPathFound is false: the view paths that do declare root paths — pass one of them as plugin instead.'],
                'templateRootPaths' => ['type' => 'array', 'description' => 'Ordered override chain (highest numeric key first): list of {key, path, absolute, exists}.'],
                'partialRootPaths' => ['type' => 'array', 'description' => 'Ordered override chain: list of {key, path, absolute, exists}.'],
                'layoutRootPaths' => ['type' => 'array', 'description' => 'Ordered override chain: list of {key, path, absolute, exists}.'],
                'resolved' => [
                    'type' => 'object',
                    'description' => 'One entry per of template/partial/layout that was actually requested: {file, found, checked}. found=false is the answer, not an empty result — checked lists every candidate file path that was tried.',
                ],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all (e.g. the console was unreachable) — distinct from viewPathFound=false, which is a real answer.'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function resolve(int $pageId, string $plugin, ?string $template = null, ?string $partial = null, ?string $layout = null, string $format = 'html'): CallToolResult
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

        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:fluid:resolve', [$pageId], $options));
    }
}
