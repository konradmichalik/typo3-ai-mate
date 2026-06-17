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
use KonradMichalik\Typo3RequestProfiler\Profiling\ProfileReader;
use Mcp\Capability\Attribute\McpTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

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
    private const SUPPORTED_SCHEMA_VERSION = 1;

    private ProfileReader $reader;

    public function __construct(string $rootDir)
    {
        // The profiler owns the read API and the schema; we only supply the
        // artifact directory (boot-free, framework-agnostic reader).
        $this->reader = new ProfileReader($rootDir.'/var/log/profiles');
    }

    #[McpTool(name: 'typo3-profiler-latest', description: 'Most recent request profile (SQL/N+1/cache/timing + page.id). Primary tool for a "slow page".')]
    public function latest(): string
    {
        $profiles = $this->reader->latest(1);
        if ([] === $profiles) {
            return ResponseEncoder::encode(['error' => 'No profiles found. Trigger a frontend request in the Development context first.']);
        }

        return ResponseEncoder::encode($this->annotate($profiles[0]));
    }

    #[McpTool(name: 'typo3-profiler-list', description: 'List the most recent request profiles as compact summaries (token, url, status, timing, queries, cache).')]
    public function list(int $limit = 20): string
    {
        $summaries = array_map($this->summarize(...), $this->reader->latest(max(1, $limit)));

        // Label the list so the AI gets a named field instead of a bare top-level array.
        return ResponseEncoder::encode(['profiles' => $summaries]);
    }

    #[McpTool(name: 'typo3-profiler-search', description: 'Search request profiles by url substring and/or HTTP status; returns matching summaries, newest first.')]
    public function search(?string $url = null, ?int $status = null, int $limit = 20): string
    {
        $matches = [];
        foreach ($this->reader->all() as $profile) {
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

        // Label the list so the AI gets a named field instead of a bare top-level array.
        return ResponseEncoder::encode(['profiles' => $matches]);
    }

    #[McpTool(name: 'typo3-profiler-get', description: 'Get a single full request profile by its token (= request_id, correlates with logs).')]
    public function get(string $token): string
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
            return ResponseEncoder::encode(['error' => 'Invalid token.']);
        }

        $profile = $this->reader->byToken($token);

        return ResponseEncoder::encode(null === $profile
            ? ['error' => sprintf('Profile "%s" not found.', $token)]
            : $this->annotate($profile));
    }

    /**
     * Flag a profile whose schema version the tool was not written against, so the
     * assistant knows the field layout may have drifted rather than silently
     * misreading it.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    private function annotate(array $profile): array
    {
        $version = $profile['schemaVersion'] ?? null;
        if (self::SUPPORTED_SCHEMA_VERSION !== $version) {
            $profile['_schema_warning'] = sprintf(
                'Profile schemaVersion %s differs from the supported version %d; some fields may be misinterpreted.',
                null === $version ? 'is missing' : '"'.Cast::string($version).'"',
                self::SUPPORTED_SCHEMA_VERSION,
            );
        }

        return $profile;
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
