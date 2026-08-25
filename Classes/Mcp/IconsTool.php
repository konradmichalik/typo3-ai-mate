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
 * IconsTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class IconsTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string|null $identifiers Comma-separated icon identifiers to check in one call, e.g. actions-add,tx-myext-plugin. A miss carries the closest registered identifiers as suggestions.
     */
    #[McpTool(
        name: 'typo3-icons',
        title: 'TYPO3 Icons',
        description: 'Whether an icon identifier is registered in this installation, and which extension provides it. registered=false is the answer, not an empty result: an unregistered identifier renders no icon at all, not a placeholder. Pass several identifiers at once; a miss carries the closest registered identifiers as suggestions, so a half-remembered name is answered rather than denied. Without arguments you get the identifier count grouped by leading segment. The registry is the resolved one, covering core T3Icons, every extension\'s Configuration/Icons.php and runtime registrations — do not grep vendor/typo3 for this.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'checked' => ['type' => 'integer', 'description' => 'Number of identifiers checked. Present only when identifiers was passed.'],
                'identifiers' => [
                    'type' => 'object',
                    'description' => 'Present only when identifiers was passed. One entry per checked identifier, keyed by the identifier itself.',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'registered' => ['type' => 'boolean', 'description' => 'Whether this exact identifier is registered. false is the answer, not an empty result: an unregistered identifier renders no icon at all, not a placeholder.'],
                            'providedBy' => ['type' => 'string', 'description' => 'Extension key that provides the icon. Present only when registered is true.'],
                            'provider' => ['type' => 'string', 'description' => 'Icon provider short name. Present only when registered is true.'],
                            'source' => ['type' => 'string', 'description' => 'Source file the icon resolves to. Present only when registered is true.'],
                            'deprecated' => ['type' => 'boolean', 'description' => 'true if the identifier is marked deprecated. Present only when true.'],
                            'suggestions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Closest registered identifiers. Present only when registered is false.'],
                        ],
                    ],
                ],
                'count' => ['type' => 'integer', 'description' => 'Total registered identifier count. Present only when no identifiers were passed.'],
                'groups' => ['type' => 'object', 'description' => 'Registered identifier count grouped by leading segment. Present only when no identifiers were passed.', 'additionalProperties' => ['type' => 'integer']],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all (e.g. the console was unreachable) — distinct from registered=false, which is a real answer.'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function lookup(?string $identifiers = null): CallToolResult
    {
        $options = null !== $identifiers && '' !== $identifiers ? ['identifiers' => $identifiers] : [];

        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:icons:lookup', [], $options));
    }
}
