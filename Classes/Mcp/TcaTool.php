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

use KonradMichalik\Typo3AiMate\Mate\Typo3CliRunner;
use Mcp\Capability\Attribute\McpTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * TcaTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TcaTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string|null $table table name whose resolved (trimmed) TCA to return; omit (or set list=true) to get only the table names
     * @param bool        $list  true returns just the list of all TCA table names instead of a table's TCA
     */
    #[McpTool(name: 'typo3-tca', title: 'TYPO3 TCA', description: 'Resolved (trimmed) TCA of a table, or the list of all TCA table names when no table is given.')]
    public function dump(?string $table = null, bool $list = false): string
    {
        if ($list || null === $table || '' === $table) {
            // Label the list so the AI gets a named field instead of a bare top-level array.
            return ResponseEncoder::encode(['tables' => $this->typo3->jsonOrError('typo3-ai-mate:tca:dump', [], ['list' => true])]);
        }

        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:tca:dump', [$table]));
    }
}
