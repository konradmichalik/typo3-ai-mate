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
 * ExtensionsTool.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final readonly class ExtensionsTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @return array{extensions: array<mixed>}
     */
    #[McpTool(name: 'typo3-extensions', description: 'Active extensions including key, version and resolved description.')]
    public function list(): array
    {
        // Wrap the list in an object: MCP structuredContent must be a record, not a bare array.
        return ['extensions' => $this->typo3->json('typo3-ai-mate:extensions:list')];
    }
}
