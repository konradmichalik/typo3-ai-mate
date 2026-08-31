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
use Symfony\AI\Mate\Attribute\MateTool;

/**
 * FlexFormTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class FlexFormTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string      $table table of the record, e.g. tt_content
     * @param int         $uid   record uid
     * @param string|null $field FlexForm column; omit when the table has exactly one, otherwise the answer lists them
     */
    #[MateTool(
        name: 'typo3-flexform',
        title: 'TYPO3 FlexForm',
        description: 'Reconcile a record\'s FlexForm against the data structure that is currently valid for it. Reports orphaned values (stored on the record but no longer declared, so they are silently ignored at runtime — this is what a renamed field looks like) and missing fields (declared but not stored, so the default applies). Use it for "the configured value stopped applying" on a plugin. The data structure is resolved through FlexFormTools from the record\'s own pointer field, so it is the structure this record actually uses, not the one a Configuration file suggests. A record without a FlexForm answers hasFlexForm=false rather than an empty structure.',
    )]
    public function diff(string $table, int $uid, ?string $field = null): string
    {
        $options = null !== $field && '' !== $field ? ['field' => $field] : [];

        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:flexform:diff', [$table, $uid], $options));
    }
}
