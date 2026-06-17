<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <km@move-elevator.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Mcp;

use KonradMichalik\Typo3AiMate\Mate\Typo3CliRunner;
use Mcp\Capability\Attribute\McpTool;

/**
 * MiddlewaresTool.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final readonly class MiddlewaresTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @return array<mixed>
     */
    #[McpTool(name: 'typo3-middlewares', description: 'Resolved PSR-15 middleware order of a stack (frontend|backend).')]
    public function list(string $stack = 'frontend'): array
    {
        return $this->typo3->jsonOrError('typo3-ai-mate:middlewares:list', [], ['stack' => $stack]);
    }
}
