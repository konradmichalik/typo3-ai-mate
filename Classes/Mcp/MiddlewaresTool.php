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
use Symfony\AI\Mate\Attribute\MateTool;

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
    #[MateTool(
        name: 'typo3-middlewares',
        title: 'TYPO3 Middlewares',
        description: 'Resolved PSR-15 middleware order of a stack (frontend|backend).',
    )]
    public function list(MiddlewareStack $stack = MiddlewareStack::Frontend): string
    {
        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:middlewares:list', [], ['stack' => $stack->value]));
    }
}
