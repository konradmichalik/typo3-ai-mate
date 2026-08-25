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
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * DbSchemaTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class DbSchemaTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string|null $table   database table to describe, e.g. tt_content or pages; omit to list all tables
     * @param string|null $pattern substring matched against the table name to filter the table list; only applies when table is omitted
     */
    #[McpTool(name: 'typo3-db-schema', title: 'TYPO3 Database Schema', description: 'The physical database schema — the counterpart to typo3-tca. Without a table: every table name with a row-count estimate. With a table: its real columns (name, type, length, nullable, default), indexes (name, columns, unique) and foreign keys. Use this to answer "why is my field not persisted" (TCA field vs. real column) or to find missing indexes; typo3-tca is the semantic model, typo3-records is the data, this is the schema underneath both.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function dump(?string $table = null, ?string $pattern = null): string
    {
        $hasTable = null !== $table && '' !== $table;
        $arguments = $hasTable ? [$table] : [];
        $options = !$hasTable && null !== $pattern && '' !== $pattern ? ['pattern' => $pattern] : [];

        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:db-schema:dump', $arguments, $options));
    }
}
