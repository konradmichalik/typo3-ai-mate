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
 * BackendModulesTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class BackendModulesTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    #[McpTool(
        name: 'typo3-backend-modules',
        title: 'TYPO3 Backend Modules',
        description: 'Which backend modules this installation registers, each with its parent, route path, access level and navigation component. Takes no arguments. The navigation component is the resolved one: a submodule declaring inheritNavigationComponent takes its parent\'s, so the value here is what the module actually renders with rather than what its own Configuration/Backend/Modules.php says. No user context is applied, so this is the registry, not one editor\'s menu.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'count' => ['type' => 'integer', 'description' => 'Total number of registered modules.'],
                'modules' => [
                    'type' => 'object',
                    'description' => 'module identifier => {parent, path, navigationComponent, access}. A key is present only when it has a non-empty value; a module without a parent is a top-level entry.',
                    'additionalProperties' => [
                        'type' => 'object',
                        'properties' => [
                            'parent' => ['type' => 'string', 'description' => 'Identifier of the parent module. Absent for a top-level module.'],
                            'path' => ['type' => 'string', 'description' => 'Route path.'],
                            'navigationComponent' => ['type' => 'string', 'description' => 'Resolved navigation component: a submodule declaring inheritNavigationComponent already shows its parent\'s value here.'],
                            'access' => ['type' => 'string'],
                        ],
                    ],
                ],
                '_hint' => ['type' => 'string', 'description' => 'Explains that navigationComponent is already resolved and that a module without a parent is top-level.'],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all (e.g. the console was unreachable).'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function list(): CallToolResult
    {
        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:backend:modules'));
    }
}
