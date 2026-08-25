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

use KonradMichalik\Typo3AiMate\Mate\{ProfilerStateProvider, ToolResult};
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * ProfilerControlTool.
 *
 * Time-boxed control over request profiling. Kept apart from PerformanceTool,
 * which only reads recorded profiles, because these tools change the state of
 * the installation.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ProfilerControlTool
{
    /**
     * @var array<string, mixed>
     */
    private const STATE_PROPERTIES = [
        'active' => ['type' => 'boolean', 'description' => 'Whether the profiling time window is currently active.'],
        'expires_at' => ['type' => ['string', 'null'], 'description' => 'ISO-8601 expiry timestamp. null when active is false.'],
        'ttl_seconds' => ['type' => ['integer', 'null'], 'description' => 'Seconds remaining until expiry. null when active is false.'],
    ];

    public function __construct(private ProfilerStateProvider $state) {}

    /**
     * @param string|null $duration how long profiling stays on, e.g. 15m, 1h, 300s (max 60m); omit for the profiler default of 15m
     */
    #[McpTool(
        name: 'typo3-profiler-start',
        title: 'TYPO3 Profiler: Start',
        description: 'Enable request profiling for a bounded time window (max 60 minutes), then exercise the site and read the resulting profiles with typo3-profiler-latest/-list. Returns the active state and its expiry. Use this before triggering a request when typo3-profiler-latest found no (or a stale) profile — it toggles profiling on, it does not return profile data itself.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                ...self::STATE_PROPERTIES,
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all: a duration string it could not parse, a duration exceeding the 60 minute ceiling enforced by ai-mate, or the underlying profiler console command failing.'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function start(?string $duration = null): CallToolResult
    {
        return ToolResult::from($this->state->activate($duration));
    }

    #[McpTool(
        name: 'typo3-profiler-stop',
        title: 'TYPO3 Profiler: Stop',
        description: 'Disable the request profiling time window again. Profiling started by the Development context or a per-request header is unaffected. Use this once the needed profile has been read, to stop profiling every subsequent request.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                ...self::STATE_PROPERTIES,
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the underlying profiler console command failed.'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function stop(): CallToolResult
    {
        return ToolResult::from($this->state->deactivate());
    }

    // readOnlyHint: true — unlike start()/stop() above, this only reads the state file.
    #[McpTool(
        name: 'typo3-profiler-status',
        title: 'TYPO3 Profiler: Status',
        description: 'Whether the profiling time window is currently active and how long it still runs. Covers only the time window from typo3-profiler-start — profiling can also be on via the Development context or a request header; read activation_mode on a recorded profile to see which mode actually applied. Use this to check before deciding whether typo3-profiler-start is needed — it only reads state, it never toggles profiling.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            // No unsupported/reason: this method only reads the local state file and
            // always answers — active=false is the real answer when nothing is set.
            'properties' => self::STATE_PROPERTIES,
        ],
    )]
    public function status(): CallToolResult
    {
        return ToolResult::from($this->state->status());
    }
}
