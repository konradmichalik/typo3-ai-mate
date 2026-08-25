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
use KonradMichalik\Typo3AiMate\Mcp\Enum\MiddlewareStack;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * MiddlewaresTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MiddlewaresTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param MiddlewareStack $stack frontend (default) | backend — which request stack's middleware order to resolve
     */
    #[McpTool(
        name: 'typo3-middlewares',
        title: 'TYPO3 Middlewares',
        description: 'Resolved PSR-15 middleware order of a stack (frontend|backend).',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'stack' => ['type' => 'string', 'enum' => ['frontend', 'backend'], 'description' => 'Echoed back stack name.'],
                'middlewares' => [
                    'type' => 'array',
                    'description' => 'Resolved middleware order for the stack, in execution order.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'identifier' => ['type' => ['string', 'null'], 'description' => 'Middleware identifier, or null when the resolver did not provide one.'],
                            'target' => ['description' => 'Middleware class/target.'],
                        ],
                    ],
                ],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all (e.g. the middleware stack could not be resolved, or the console was unreachable).'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function list(MiddlewareStack $stack = MiddlewareStack::Frontend): CallToolResult
    {
        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:middlewares:list', [], ['stack' => $stack->value]));
    }
}
