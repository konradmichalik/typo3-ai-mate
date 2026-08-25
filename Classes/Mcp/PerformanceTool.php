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

use KonradMichalik\Typo3AiMate\Mate\{ProfileProvider, ToolResult};
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

use function sprintf;

/**
 * PerformanceTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PerformanceTool
{
    /**
     * @var array<string, mixed>
     */
    private const SUMMARY_PROPERTIES = [
        'token' => ['type' => 'string', 'description' => 'Profiler token (= request_id, correlates with logs).'],
        'time' => ['type' => 'string', 'description' => 'When the profile was recorded.'],
        'url' => ['type' => 'string', 'description' => 'Request URL (PII/credentials redacted).'],
        'status' => ['type' => 'integer', 'description' => 'HTTP status code of the profiled request.'],
        'page' => ['type' => ['object', 'null'], 'description' => 'Page id/type, when the request resolved to a page.'],
        'cache_hit' => ['type' => ['boolean', 'null'], 'description' => 'Whether the page cache was hit.'],
        'total_ms' => ['type' => ['number', 'null'], 'description' => 'Total request time in milliseconds.'],
        'query_count' => ['type' => ['integer', 'null'], 'description' => 'Number of database queries executed.'],
        'duplicate_queries' => ['type' => 'integer', 'description' => 'Number of distinct queries that ran more than once (N+1 signal).'],
        'activation_mode' => ['type' => ['string', 'null'], 'description' => 'Why this profile was recorded (stateFile, context, header) — tells a near-empty profile from one taken under the wrong activation mode.'],
        'resource_uri' => ['type' => 'string', 'description' => 'MCP resource URI to read the full profile.'],
    ];

    public function __construct(private ProfileProvider $profiles) {}

    #[McpTool(
        name: 'typo3-profiler-latest',
        title: 'TYPO3 Profiler: Latest',
        description: 'Compact summary of the most recent request profile (timing, query count, N+1, cache, page.id) plus a resource_uri to read the full profile. Use this first for a single "this page is slow" complaint — no token or filter needed. Use typo3-profiler-list to browse several requests, or typo3-profiler-get once you already have a token.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                ...self::SUMMARY_PROPERTIES,
                'unsupported' => ['type' => 'boolean', 'description' => 'true when no profiles have been recorded yet — distinct from a near-empty profile (low query_count etc.), which is a real answer.'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function latest(): CallToolResult
    {
        $profile = $this->profiles->rawLatest();

        return ToolResult::from(null === $profile
            ? ['error' => 'No profiles found. Trigger a frontend request in the Development context first.']
            : $this->profiles->summarize($profile));
    }

    /**
     * @param int $limit maximum number of recent profiles to list
     */
    #[McpTool(
        name: 'typo3-profiler-list',
        title: 'TYPO3 Profiler: List',
        description: 'List the most recent request profiles as compact summaries (token, url, status, timing, queries, cache), each with a resource_uri for the full profile. Use this to browse recent activity when no single request is known yet — not for one specific complaint (typo3-profiler-latest) or a known url/status filter (typo3-profiler-search).',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'profiles' => [
                    'type' => 'array',
                    'description' => 'Newest-first summaries, each with a resource_uri to read the full profile. Empty when no profiles have been recorded — that is a real answer, not a failure: this tool never fails to answer.',
                    'items' => ['type' => 'object', 'properties' => self::SUMMARY_PROPERTIES],
                ],
            ],
        ],
    )]
    public function list(int $limit = 20): CallToolResult
    {
        // Label the list so the AI gets a named field instead of a bare top-level array.
        return ToolResult::from(['profiles' => $this->profiles->summaries($limit)]);
    }

    /**
     * @param string|null $url    substring matched against the request URL; omit to match any URL
     * @param int|null    $status HTTP status code to match (e.g. 500); omit to match any status.
     * @param int         $limit  maximum number of matching profiles to return
     */
    #[McpTool(
        name: 'typo3-profiler-search',
        title: 'TYPO3 Profiler: Search',
        description: 'Search request profiles by url substring and/or HTTP status; returns matching summaries (with resource_uri), newest first. Use this when you know a URL substring or status code to filter by (e.g. "the 500 on /checkout") — otherwise use typo3-profiler-list to browse or typo3-profiler-latest for the most recent request.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'profiles' => [
                    'type' => 'array',
                    'description' => 'Newest-first matches, each with a resource_uri to read the full profile. Empty when nothing matches url/status — that is a real answer, not a failure: this tool never fails to answer.',
                    'items' => ['type' => 'object', 'properties' => self::SUMMARY_PROPERTIES],
                ],
            ],
        ],
    )]
    public function search(?string $url = null, ?int $status = null, int $limit = 20): CallToolResult
    {
        // Label the list so the AI gets a named field instead of a bare top-level array.
        return ToolResult::from(['profiles' => $this->profiles->search($url, $status, $limit)]);
    }

    /**
     * @param string $token profiler token (= request_id, correlates with logs) identifying the profile
     */
    #[McpTool(
        name: 'typo3-profiler-get',
        title: 'TYPO3 Profiler: Get',
        description: 'Compact summary of a single request profile by its token (= request_id, correlates with logs), plus a resource_uri to read the full profile. Use this once you already have a token — typically a log entry\'s request_id, or a result from typo3-profiler-list/-search.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                ...self::SUMMARY_PROPERTIES,
                'unsupported' => ['type' => 'boolean', 'description' => 'true when the token does not match any recorded profile (unknown or invalid token).'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function get(string $token): CallToolResult
    {
        $profile = $this->profiles->rawByToken($token);

        return ToolResult::from(null === $profile
            ? ['error' => sprintf('Profile "%s" not found.', $token)]
            : $this->profiles->summarize($profile));
    }
}
