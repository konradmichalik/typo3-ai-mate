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
 * TcaTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class TcaTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string|null $table      table name whose resolved TCA (capabilities, record types, relations, trimmed columns) to return; omit (or set list=true) to get only the table names
     * @param bool        $list       true returns just the list of all TCA table names instead of a table's TCA
     * @param string|null $recordType Limit columns, relations and recordTypes to one type value, e.g. textmedia. Answers with availableRecordTypes when the value is unknown.
     * @param string|null $fields     comma-separated field names to limit columns and relations to, e.g. header,bodytext
     */
    #[McpTool(
        name: 'typo3-tca',
        title: 'TYPO3 TCA',
        description: 'Resolved TCA of a table, or the list of all TCA table names when no table is given. A table returns capabilities (softDelete/workspace/language/sorting), recordTypes, relations (field => resolved target table + relationship type, e.g. a file field resolves to sys_file_reference instead of leaving type=file for you to interpret) and the trimmed columns. Ask narrowly: recordType or fields limits columns and relations to what you asked about, which for tt_content is the difference between a few hundred bytes and 15 kB that every later turn re-reads. recordTypes lists the fields shared by all types once under shared and only the additions per type.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'description' => 'Shape depends on the request. Without a table (or list=true): {tables}. With a table: the table description below, or the unknown-record-type miss shape.',
            'properties' => [
                'tables' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'All TCA table names. Present only when no table was given (or list=true).'],
                'table' => ['type' => 'string', 'description' => 'Echoed back table name. Present when a table was given.'],
                'answeredBy' => ['type' => 'string', 'enum' => ['schema-api', 'schema-api+tca-fallback'], 'description' => 'Which source built columns: schema-api alone, or schema-api with a per-field fallback to raw TCA extraction for fields the Schema API does not build (rare, non-standard config).'],
                'ctrl' => ['type' => 'object', 'description' => 'Relevant TCA ctrl keys: title, label, type, sortby.'],
                'capabilities' => ['type' => 'object', 'description' => 'softDelete (field name or null), workspace, language, sorting (field name or null).'],
                'recordTypes' => ['type' => 'object', 'description' => 'Field names visible per record type value, collapsed to a shared set plus per-type additions.'],
                'relations' => ['type' => 'object', 'description' => 'field name => {type, toTables}: the resolved relation target table(s) and relationship type.'],
                'columns' => ['type' => 'object', 'description' => 'field name => {label, type, renderType, foreign_table, eval, displayCond}.'],
                '_hint' => ['type' => 'string', 'description' => 'Present when recordType or fields scoped the response.'],
                'unknownFields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Present only when fields named columns the table does not have.'],
                'recordType' => ['type' => 'string', 'description' => 'Echoed back. Present only on the unknown-record-type miss.'],
                'recordTypeFound' => ['type' => 'boolean', 'description' => 'Present only when recordType was given and is unknown. false is the answer, not an empty result.'],
                'availableRecordTypes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Present only when recordTypeFound is false: the values recordType accepts.'],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all (unknown table name, or the console was unreachable).'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function dump(?string $table = null, bool $list = false, ?string $recordType = null, ?string $fields = null): CallToolResult
    {
        if ($list || null === $table || '' === $table) {
            $names = $this->typo3->jsonOrError('typo3-ai-mate:tca:dump', [], ['list' => true]);

            // Label the list so the AI gets a named field instead of a bare top-level
            // array — unless the call itself failed, which ToolResult must still see
            // as {"error": "..."} to report it as unsupported rather than as a table list.
            return ToolResult::from(['error'] === array_keys($names) ? $names : ['tables' => $names]);
        }

        $options = [];
        if (null !== $recordType && '' !== $recordType) {
            $options['record-type'] = $recordType;
        }
        if (null !== $fields && '' !== $fields) {
            $options['fields'] = $fields;
        }

        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:tca:dump', [$table], $options));
    }
}
