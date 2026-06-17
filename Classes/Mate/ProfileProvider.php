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

namespace KonradMichalik\Typo3AiMate\Mate;

use KonradMichalik\Typo3AiMate\Support\Cast;
use KonradMichalik\Typo3RequestProfiler\Profiling\ProfileReader;

use function count;
use function is_array;
use function sprintf;

/**
 * ProfileProvider.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final readonly class ProfileProvider
{
    public const RESOURCE_SCHEME = 'typo3-profiler';
    private const SUPPORTED_SCHEMA_VERSION = 1;

    private ProfileReader $reader;

    public function __construct(string $rootDir)
    {
        // The profiler owns the read API and the schema; we only supply the
        // artifact directory (boot-free, framework-agnostic reader).
        $this->reader = new ProfileReader($rootDir.'/var/log/profiles');
    }

    /**
     * @return array<string, mixed>|null newest full profile, or null if none recorded
     */
    public function rawLatest(): ?array
    {
        $profiles = $this->reader->latest(1);

        return [] === $profiles ? null : $profiles[0];
    }

    /**
     * @return array<string, mixed>|null full profile, or null for an unknown/invalid token
     */
    public function rawByToken(string $token): ?array
    {
        return $this->reader->byToken($token);
    }

    /**
     * Newest-first triage summaries.
     *
     * @return list<array<string, mixed>>
     */
    public function summaries(int $limit): array
    {
        return array_map($this->summarize(...), $this->reader->latest(max(1, $limit)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(?string $url, ?int $status, int $limit): array
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

        return $matches;
    }

    public function resourceUri(string $token): string
    {
        return self::RESOURCE_SCHEME.'://profile/'.$token;
    }

    /**
     * A compact triage summary plus a resource_uri to read the full profile.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    public function summarize(array $profile): array
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
            'resource_uri' => $this->resourceUri(Cast::string($profile['token'] ?? '')),
        ];
    }

    /**
     * Add a schema-drift warning when the profile's schemaVersion is not the one
     * this bridge was written against, so the assistant knows the field layout may
     * have changed rather than silently misreading it.
     *
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    public function annotate(array $profile): array
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
}
