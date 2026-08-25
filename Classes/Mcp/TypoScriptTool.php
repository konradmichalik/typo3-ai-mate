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
use KonradMichalik\Typo3AiMate\Mcp\Enum\TypoScriptType;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * TypoScriptTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TypoScriptTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param int            $pageId page UID whose resolved frontend TypoScript should be dumped
     * @param TypoScriptType $type   setup (default, the object/setup tree) | constants (the constants tree)
     * @param string|null    $path   Dotted scope to limit large output to one branch, e.g. lib.foo. Omitted returns a top-level overview.
     * @param bool           $full   return the entire resolved tree instead of the top-level overview (can be very large)
     */
    #[McpTool(
        name: 'typo3-typoscript',
        title: 'TYPO3 TypoScript',
        description: 'Resolved frontend TypoScript (setup|constants) of a page. Without a path you get a top-level overview; drill in with a dotted path (e.g. lib.foo) or pass full=true for the whole tree. A path that does not exist answers with found=false plus the keys that do exist at the deepest segment that resolved — read those instead of guessing another path.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'description' => 'Shape depends on the request: a top-level overview or the full tree is an arbitrary object mirroring the TypoScript structure (additional properties beyond those below). A dotted path that does not resolve returns the miss fields instead.',
            'properties' => [
                '_hint' => ['type' => 'string', 'description' => 'Present on the top-level overview: explains that values are previews and how to drill in.'],
                'found' => ['type' => 'boolean', 'description' => 'Present only when path was given and did not resolve. false is the answer, not an empty result.'],
                'resolvedUpTo' => ['type' => ['string', 'null'], 'description' => 'Present only when found is false: the deepest dotted segment of path that did resolve.'],
                'siblingCount' => ['type' => 'integer', 'description' => 'Present only when found is false: total number of keys that exist at resolvedUpTo.'],
                'siblings' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Present only when found is false: the keys that do exist at resolvedUpTo, so a wrong guess costs one call, not several.'],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all (e.g. the console was unreachable) — distinct from found=false, which is a real answer.'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
            'additionalProperties' => true,
        ],
    )]
    public function dump(int $pageId, TypoScriptType $type = TypoScriptType::Setup, ?string $path = null, bool $full = false): CallToolResult
    {
        $options = ['type' => $type->value];
        if (null !== $path && '' !== $path) {
            $options['path'] = $path;
        }
        if ($full) {
            $options['full'] = true;
        }

        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:typoscript:dump', [$pageId], $options));
    }
}
