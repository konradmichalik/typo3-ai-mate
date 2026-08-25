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
 * FluidNamespacesTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class FluidNamespacesTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    #[McpTool(
        name: 'typo3-fluid-namespaces',
        title: 'TYPO3 Fluid Namespaces',
        description: 'Which Fluid ViewHelper prefixes a template may use without declaring them, mapped to the PHP namespaces they resolve to in order. Takes no arguments. Every other namespace has to be declared per template with an xmlns attribute, so this answers "is <foo:bar> available here" in one call. Resolved from the ViewHelperResolver, which on v14 has already merged Configuration/Fluid/Namespaces.php of every package with the deprecated TYPO3_CONF_VARS registration and applied any ModifyNamespacesEvent listener — none of which is visible in a single configuration file.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'count' => ['type' => 'integer', 'description' => 'Number of registered prefixes.'],
                'namespaces' => [
                    'type' => 'object',
                    'description' => 'prefix => ordered list of PHP namespaces it resolves to. A prefix mapped to an empty list is registered as explicitly ignored (e.g. xmlns:xsi), not unregistered — that is the answer, not an empty result.',
                    'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                '_hint' => ['type' => 'string', 'description' => 'Explains that these prefixes need no per-template xmlns declaration, unlike any other namespace.'],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all (e.g. the console was unreachable).'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function list(): CallToolResult
    {
        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:fluid:namespaces'));
    }
}
