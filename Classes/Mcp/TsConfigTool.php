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
use KonradMichalik\Typo3AiMate\Mcp\Enum\TsConfigType;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * TsConfigTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TsConfigTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param int          $pageId page UID whose rootline-merged Page TSconfig should be dumped
     * @param TsConfigType $type   page (default; mod.*, TCEFORM, TCEMAIN, RTE) | user (per backend user)
     * @param int|null     $user   BE user UID — required when type=user
     * @param string|null  $path   Dotted scope to limit large output to one branch, e.g. mod.web_layout. Omitted returns a top-level overview.
     * @param bool         $full   return the entire resolved tree instead of the top-level overview (can be very large)
     */
    #[McpTool(
        name: 'typo3-tsconfig',
        title: 'TYPO3 Page/User TSconfig',
        description: 'Resolved Page TSconfig (rootline-merged: mod.*, TCEFORM, TCEMAIN, RTE) or User TSconfig — the backend configuration layer that no single file reveals. Distinct from frontend TypoScript (typo3-typoscript). Without a path you get a top-level overview; drill in with a dotted path (e.g. mod.web_layout.BackendLayouts) or pass full=true for the whole tree.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'description' => 'Shape depends on the request: a top-level overview or the full tree is an arbitrary object mirroring the TSconfig structure (additional properties beyond those below). A dotted path that does not resolve returns the miss fields instead.',
            'properties' => [
                '_hint' => ['type' => 'string', 'description' => 'Present on the top-level overview: explains that values are previews and how to drill in.'],
                'found' => ['type' => 'boolean', 'description' => 'Present only when path was given and did not resolve. false is the answer, not an empty result.'],
                'resolvedUpTo' => ['type' => ['string', 'null'], 'description' => 'Present only when found is false: the deepest dotted segment of path that did resolve.'],
                'siblingCount' => ['type' => 'integer', 'description' => 'Present only when found is false: total number of keys that exist at resolvedUpTo.'],
                'siblings' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Present only when found is false: the keys that do exist at resolvedUpTo, so a wrong guess costs one call, not several.'],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all: an invalid type, a type=user request without a valid --user uid, or the console was unreachable — distinct from found=false, which is a real answer.'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
            'additionalProperties' => true,
        ],
    )]
    public function dump(int $pageId, TsConfigType $type = TsConfigType::Page, ?int $user = null, ?string $path = null, bool $full = false): CallToolResult
    {
        $options = ['type' => $type->value];
        if (null !== $user) {
            $options['user'] = $user;
        }
        if (null !== $path && '' !== $path) {
            $options['path'] = $path;
        }
        if ($full) {
            $options['full'] = true;
        }

        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:tsconfig:dump', [$pageId], $options));
    }
}
