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
use KonradMichalik\Typo3AiMate\Support\{ProfileComparison, ProfileTriage};
use Symfony\AI\Mate\Attribute\MateTool;

use function array_slice;
use function count;
use function sprintf;

/**
 * PerformanceTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class PerformanceTool
{
    private const NO_PROFILES = 'No profiles found. Trigger a frontend request in the Development context first.';
    private const HEADER_FIELDS = ['token', 'url', 'status', 'resource_uri'];
    private const TRIAGE_FIELDS = ['token', 'url', 'status', 'total_ms', 'query_count', 'resource_uri'];
    private const TRIAGE_MAX_ENTRIES = 5;

    public function __construct(private ProfileProvider $profiles) {}

    #[MateTool(
        name: 'typo3-profiler-latest',
        title: 'TYPO3 Profiler: Latest',
        description: 'Compact summary of the most recent request profile (timing, query count, N+1, cache, page.id) plus a resource_uri to read the full profile. Use this first for a single "this page is slow" complaint — no token or filter needed. Use typo3-profiler-list to browse several requests, or typo3-profiler-get once you already have a token.',
    )]
    public function latest(): string
    {
        $profile = $this->profiles->rawLatest();

        return ToolResult::untrusted(null === $profile
            ? ['error' => self::NO_PROFILES]
            : $this->profiles->summarize($profile));
    }

    /**
     * @param int $limit maximum number of recent profiles to list; defaults to 20
     */
    #[MateTool(
        name: 'typo3-profiler-list',
        title: 'TYPO3 Profiler: List',
        description: 'List the most recent request profiles as compact summaries (token, url, status, timing, queries, cache), each with a resource_uri for the full profile. Use this to browse recent activity when no single request is known yet — not for one specific complaint (typo3-profiler-latest) or a known url/status filter (typo3-profiler-search).',
    )]
    public function list(int $limit = 20): string
    {
        // Label the list so the AI gets a named field instead of a bare top-level array.
        return ToolResult::untrusted(['profiles' => $this->profiles->summaries($limit)]);
    }

    /**
     * @param string|null $urlContains substring matched against the request URL; omit to match any URL
     * @param int|null    $status      HTTP status code to match (e.g. 500); omit to match any status.
     * @param int         $limit       maximum number of matching profiles to return; defaults to 20
     */
    #[MateTool(
        name: 'typo3-profiler-search',
        title: 'TYPO3 Profiler: Search',
        description: 'Search request profiles by url substring and/or HTTP status; returns matching summaries (with resource_uri), newest first. Use this when you know a URL substring or status code to filter by (e.g. "the 500 on /checkout") — otherwise use typo3-profiler-list to browse or typo3-profiler-latest for the most recent request.',
    )]
    public function search(?string $urlContains = null, ?int $status = null, int $limit = 20): string
    {
        // Label the list so the AI gets a named field instead of a bare top-level array.
        return ToolResult::untrusted(['profiles' => $this->profiles->search($urlContains, $status, $limit)]);
    }

    /**
     * @param string $token profiler token (= request_id, correlates with logs) identifying the profile
     */
    #[MateTool(
        name: 'typo3-profiler-get',
        title: 'TYPO3 Profiler: Get',
        description: 'Compact summary of a single request profile by its token (= request_id, correlates with logs), plus a resource_uri to read the full profile. Use this once you already have a token — typically a log entry\'s request_id, or a result from typo3-profiler-list/-search.',
    )]
    public function get(string $token): string
    {
        $profile = $this->profiles->rawByToken($token);

        return ToolResult::untrusted(null === $profile
            ? ['error' => sprintf('Profile "%s" not found.', $token)]
            : $this->profiles->summarize($profile));
    }

    /**
     * @param string $a token of the baseline profile, typically the request before the change
     * @param string $b token of the profile to compare against the baseline, typically the request after the change
     */
    #[MateTool(
        name: 'typo3-profiler-compare',
        title: 'TYPO3 Profiler: Compare',
        description: 'Compare two request profiles by token: deltas of timing, memory, query count and time, duplicate queries, HTTP calls and log entries by level, the largest differences first, the cache state of both, and the duplicate queries, log components and events that changed most. Use this for "did my change make it better" once you have two tokens from typo3-profiler-list/-search; for a single request use typo3-profiler-get.',
    )]
    public function compare(string $a, string $b): string
    {
        $profileA = $this->profiles->rawByToken($a);
        $profileB = $this->profiles->rawByToken($b);
        if (null === $profileA || null === $profileB) {
            return ToolResult::untrusted(['error' => sprintf('Profile "%s" not found.', null === $profileA ? $a : $b)]);
        }

        return ToolResult::untrusted([
            'a' => $this->pick($this->profiles->summarize($profileA), self::HEADER_FIELDS),
            'b' => $this->pick($this->profiles->summarize($profileB), self::HEADER_FIELDS),
        ] + ProfileComparison::compare($profileA, $profileB));
    }

    /**
     * @param int $limit number of most recent profiles to consider; defaults to 20
     */
    #[MateTool(
        name: 'typo3-profiler-triage',
        title: 'TYPO3 Profiler: Triage',
        description: 'Rank the most recent request profiles by what is worth a look (HTTP 5xx, exceptions, error-level log entries, deprecations, duration or query count at least twice the median, N+1 suspects), each with its reasons and token for typo3-profiler-get. Returns at most 5 entries. Use this to decide which of many recent requests to investigate; typo3-profiler-list lists them newest first without judging them.',
    )]
    public function triage(int $limit = 20): string
    {
        $profiles = $this->profiles->rawRecent($limit);
        if ([] === $profiles) {
            return ToolResult::untrusted(['error' => self::NO_PROFILES]);
        }

        $ranked = ProfileTriage::rank($profiles);
        $result = [
            'considered' => count($profiles),
            'flagged' => count($ranked),
            'profiles' => array_map(
                fn (array $entry): array => $this->pick($this->profiles->summarize($entry['profile']), self::TRIAGE_FIELDS)
                    + ['reasons' => $entry['reasons']],
                array_slice($ranked, 0, self::TRIAGE_MAX_ENTRIES),
            ),
        ];
        if (count($ranked) > self::TRIAGE_MAX_ENTRIES) {
            $result['_truncated'] = true;
        }

        return ToolResult::untrusted($result);
    }

    /**
     * @param array<string, mixed> $summary
     * @param list<string>         $fields
     *
     * @return array<string, mixed>
     */
    private function pick(array $summary, array $fields): array
    {
        return array_intersect_key($summary, array_flip($fields));
    }
}
