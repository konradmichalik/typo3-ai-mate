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
 * UpgradeWizardsTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class UpgradeWizardsTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    #[McpTool(
        name: 'typo3-upgrade-wizards',
        title: 'TYPO3 Upgrade Wizards',
        description: 'List all TYPO3 upgrade wizards (pending and done) with identifier, title, description and status — which DB/config migrations are still outstanding. Read-only; running a wizard is not exposed.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'wizards' => [
                    'type' => 'array',
                    'description' => 'All upgrade wizards, pending and done. Empty is a real answer (nothing registered), not a failure.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'identifier' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'status' => ['type' => 'string', 'enum' => ['DONE', 'AVAILABLE'], 'description' => 'Whether the wizard has already run.'],
                            'updateNecessary' => ['type' => 'boolean', 'description' => 'Whether the wizard still needs to run.'],
                        ],
                    ],
                ],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all (e.g. the console was unreachable).'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function list(): CallToolResult
    {
        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:upgrade:wizards'));
    }
}
