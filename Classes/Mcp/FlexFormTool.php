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
    #[McpTool(
        name: 'typo3-flexform',
        title: 'TYPO3 FlexForm',
        description: 'Reconcile a record\'s FlexForm against the data structure that is currently valid for it. Reports orphaned values (stored on the record but no longer declared, so they are silently ignored at runtime — this is what a renamed field looks like) and missing fields (declared but not stored, so the default applies). Use it for "the configured value stopped applying" on a plugin. The data structure is resolved through FlexFormTools from the record\'s own pointer field, so it is the structure this record actually uses, not the one a Configuration file suggests. A record without a FlexForm answers hasFlexForm=false rather than an empty structure.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'description' => 'Shape depends on how far resolution got: no flex column, an ambiguous/unknown field, a missing record, an unresolvable data structure, or the full diff below.',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'Echoed back table name.'],
                'uid' => ['type' => 'integer', 'description' => 'Echoed back record uid.'],
                'field' => ['type' => 'string', 'description' => 'The resolved (or requested) FlexForm column name.'],
                'flexFields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Present when field could not be resolved unambiguously: every FlexForm column table actually has (empty means table has none at all — no record of it can carry a FlexForm).'],
                'flexField' => ['type' => 'boolean', 'description' => 'Present only when a requested field is not a FlexForm column of table. false is the answer, not an empty result.'],
                'recordFound' => ['type' => 'boolean', 'description' => 'Present only when uid does not exist in table (deleted rows included). false is the answer, not an empty result.'],
                'hasFlexForm' => ['type' => 'boolean', 'description' => 'Whether the record stores any FlexForm value in field at all. false is the answer, not an empty structure — there is nothing to diff.'],
                'dataStructureResolved' => ['type' => 'boolean', 'description' => 'Present only when hasFlexForm is true. false means the pointer field (e.g. CType or list_type) references a plugin/structure that could not be resolved — see error.'],
                'dataStructureIdentifier' => ['type' => 'string', 'description' => 'Present only when dataStructureResolved is true.'],
                'orphanedCount' => ['type' => 'integer', 'description' => 'Present only when dataStructureResolved is true: number of stored values no longer declared by the data structure.'],
                'missingCount' => ['type' => 'integer', 'description' => 'Present only when dataStructureResolved is true: number of declared fields not stored on the record.'],
                'orphaned' => ['type' => 'object', 'description' => 'Present only when dataStructureResolved is true: stored values (redacted/truncated) that are no longer declared — silently ignored at runtime. This is what a renamed field looks like.', 'additionalProperties' => true],
                'missing' => ['type' => 'object', 'description' => 'Present only when dataStructureResolved is true: declared fields with no stored value, so their default applies.', 'additionalProperties' => true],
                'matched' => ['type' => 'object', 'description' => 'Present only when dataStructureResolved is true: stored values (redacted/truncated) that do match the current data structure.', 'additionalProperties' => true],
                'error' => ['type' => 'string', 'description' => 'Present only when dataStructureResolved is false: the resolver exception message.'],
                '_hint' => ['type' => 'string', 'description' => 'Explains the response shape and, when every stored value is orphaned and nothing matched, that the record likely resolves to a different data structure than expected.'],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all — including a session-storage table blocked outright, or the console being unreachable.'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function diff(string $table, int $uid, ?string $field = null): CallToolResult
    {
        $options = null !== $field && '' !== $field ? ['field' => $field] : [];

        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:flexform:diff', [$table, $uid], $options));
    }
}
