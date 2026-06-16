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

use KonradMichalik\Typo3AiMate\Support\Cast;
use Mcp\Capability\Attribute\McpTool;

use function array_slice;
use function count;
use function is_array;
use function sprintf;

/**
 * PerformanceTool.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final readonly class PerformanceTool
{
    public function __construct(private string $rootDir) {}

    /**
     * @return array<mixed>
     */
    #[McpTool(name: 'typo3-profiler-latest', description: 'Most recent request profile (SQL/N+1/cache/timing + page.id). Primary tool for a "slow page".')]
    public function latest(): array
    {
        $files = $this->profileFiles();
        $latest = end($files);
        if (false === $latest) {
            return ['error' => 'No profiles found. Trigger a frontend request in the Development context first.'];
        }

        return $this->read($latest) ?? ['error' => 'Latest profile could not be read.'];
    }

    /**
     * @return array{profiles: list<array<string, mixed>>}
     */
    #[McpTool(name: 'typo3-profiler-list', description: 'List the most recent request profiles as compact summaries (token, url, status, timing, queries, cache).')]
    public function list(int $limit = 20): array
    {
        $files = array_reverse($this->profileFiles());
        $summaries = [];
        foreach (array_slice($files, 0, max(1, $limit)) as $file) {
            $profile = $this->read($file);
            if (null !== $profile) {
                $summaries[] = $this->summarize($profile);
            }
        }

        // Wrap the list in an object: MCP structuredContent must be a record, not a bare array.
        return ['profiles' => $summaries];
    }

    /**
     * @return array{profiles: list<array<string, mixed>>}
     */
    #[McpTool(name: 'typo3-profiler-search', description: 'Search request profiles by url substring and/or HTTP status; returns matching summaries, newest first.')]
    public function search(?string $url = null, ?int $status = null, int $limit = 20): array
    {
        $files = array_reverse($this->profileFiles());
        $matches = [];
        foreach ($files as $file) {
            $profile = $this->read($file);
            if (null === $profile) {
                continue;
            }
            if (null !== $url && '' !== $url && !str_contains(Cast::string($profile['url'] ?? ''), $url)) {
                continue;
            }
            if (null !== $status && Cast::int($profile['status'] ?? 0) !== $status) {
                continue;
            }
            $matches[] = $this->summarize($profile);
            if (count($matches) >= max(1, $limit)) {
                break;
            }
        }

        // Wrap the list in an object: MCP structuredContent must be a record, not a bare array.
        return ['profiles' => $matches];
    }

    /**
     * @return array<mixed>
     */
    #[McpTool(name: 'typo3-profiler-get', description: 'Get a single full request profile by its token (= request_id, correlates with logs).')]
    public function get(string $token): array
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            return ['error' => 'Invalid token.'];
        }

        return $this->read($this->directory().'/'.$token.'.json')
            ?? ['error' => sprintf('Profile "%s" not found.', $token)];
    }

    private function directory(): string
    {
        return $this->rootDir.'/var/log/profiles';
    }

    /**
     * @return list<string> profile files sorted oldest -> newest
     */
    private function profileFiles(): array
    {
        $files = glob($this->directory().'/*.json') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b));

        return $files;
    }

    /**
     * @return array<mixed>|null
     */
    private function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $contents = file_get_contents($file);
        if (false === $contents) {
            return null;
        }
        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<mixed> $profile
     *
     * @return array<string, mixed>
     */
    private function summarize(array $profile): array
    {
        $cache = is_array($profile['cache'] ?? null) ? $profile['cache'] : [];
        $timing = is_array($profile['timing'] ?? null) ? $profile['timing'] : [];
        $queries = is_array($profile['queries'] ?? null) ? $profile['queries'] : [];

        return [
            'token' => $profile['token'] ?? null,
            'time' => $profile['time'] ?? null,
            'url' => $profile['url'] ?? null,
            'status' => $profile['status'] ?? null,
            'page' => $profile['page'] ?? null,
            'cache_hit' => $cache['hit'] ?? null,
            'total_ms' => $timing['total_ms'] ?? null,
            'query_count' => $queries['count'] ?? null,
            'duplicate_queries' => count(is_array($profile['duplicate_queries'] ?? null) ? $profile['duplicate_queries'] : []),
        ];
    }
}
