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
 * EventsTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class EventsTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string|null $event substring matched against the event class name to filter the registry; omit to list all events
     */
    #[McpTool(
        name: 'typo3-events',
        title: 'TYPO3 Event Listeners',
        description: 'Resolved PSR-14 event listener registry (which listeners fire for which event), optionally filtered by event class substring.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'events' => [
                    'type' => 'array',
                    'description' => 'One entry per matching event, up to eventCount when _truncated is true. An empty array means no registered event matches the event filter (or, without a filter, no listeners are registered at all) — that is the real answer, not a failure.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'event' => ['type' => 'string', 'description' => 'Fully qualified event class name.'],
                            'listeners' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'identifier' => ['type' => 'string'],
                                        'service' => ['type' => 'string'],
                                        'method' => ['type' => 'string', 'description' => 'Defaults to __invoke when the listener declares no explicit method.'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'eventCount' => ['type' => 'integer', 'description' => 'Total number of matching events before truncation.'],
                '_truncated' => ['type' => 'boolean', 'description' => 'true if events was capped at 100 entries; eventCount still reports the real total.'],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all (e.g. the console was unreachable) — distinct from an empty events array, which is a real answer.'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function list(?string $event = null): CallToolResult
    {
        $options = null !== $event && '' !== $event ? ['event' => $event] : [];

        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:events:list', [], $options));
    }
}
