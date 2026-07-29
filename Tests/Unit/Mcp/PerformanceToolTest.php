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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Mcp;

use KonradMichalik\Ttt\Assertion\JsonAssertions;
use KonradMichalik\Typo3AiMate\Mate\ProfileProvider;
use KonradMichalik\Typo3AiMate\Mcp\PerformanceTool;
use KonradMichalik\Typo3AiMate\Tests\Unit\ProfileFixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PerformanceToolTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class PerformanceToolTest extends TestCase
{
    use DecodesResponses;
    use JsonAssertions;
    use ProfileFixtures;

    protected function setUp(): void
    {
        $this->initProfilesDir('typo3-ai-mate-perf-');

        // Three profiles with increasing mtime so order is deterministic.
        $this->writeProfile('aaa', ['url' => '/', 'status' => 200, 'cache' => ['hit' => true], 'timing' => ['total_ms' => 10], 'queries' => ['count' => 2]], 1_000_000_100);
        $this->writeProfile('bbb', ['url' => '/slow', 'status' => 200, 'cache' => ['hit' => false], 'timing' => ['total_ms' => 500], 'queries' => ['count' => 30], 'page' => ['id' => 42, 'type' => 0], 'duplicate_queries' => [['sql' => 'SELECT ...', 'count' => 25]]], 1_000_000_200);
        $this->writeProfile('ccc', ['url' => '/error', 'status' => 500, 'cache' => ['hit' => false], 'timing' => ['total_ms' => 80], 'queries' => ['count' => 5]], 1_000_000_300);
    }

    protected function tearDown(): void
    {
        $this->cleanupProfilesDir();
    }

    #[Test]
    public function latestReturnsASummaryOfTheNewestProfileWithResourceUri(): void
    {
        $summary = $this->decode($this->tool()->latest());

        self::assertJsonPath($summary, 'token', 'ccc');
        self::assertJsonPath($summary, 'url', '/error');
        self::assertJsonPath($summary, 'status', 500);
        self::assertJsonPath($summary, 'resource_uri', 'typo3-profiler://profile/ccc');
    }

    #[Test]
    public function latestReportsAnErrorWhenNoProfilesExist(): void
    {
        $empty = new PerformanceTool(new ProfileProvider(sys_get_temp_dir().'/typo3-ai-mate-empty-'.bin2hex(random_bytes(8))));

        self::assertJsonHasPath($this->decode($empty->latest()), 'error');
    }

    #[Test]
    public function listReturnsSummariesNewestFirstEachWithAResourceUri(): void
    {
        $list = $this->profiles($this->tool()->list());

        self::assertSame(['ccc', 'bbb', 'aaa'], array_column($list, 'token'));

        $slow = $list[1];
        self::assertIsArray($slow);
        self::assertSame('/slow', $slow['url']);
        self::assertFalse($slow['cache_hit']);
        self::assertSame(500, $slow['total_ms'] ?? null);
        self::assertSame(30, $slow['query_count']);
        self::assertSame(1, $slow['duplicate_queries']);
        self::assertSame(['id' => 42, 'type' => 0], $slow['page']);
        self::assertSame('typo3-profiler://profile/bbb', $slow['resource_uri']);
    }

    #[Test]
    public function listRespectsTheLimit(): void
    {
        $list = $this->profiles($this->tool()->list(1));

        self::assertCount(1, $list);
        self::assertIsArray($list[0]);
        self::assertSame('ccc', $list[0]['token']);
    }

    #[Test]
    public function searchFiltersByUrlSubstring(): void
    {
        $matches = $this->profiles($this->tool()->search('/slow'));

        self::assertCount(1, $matches);
        self::assertIsArray($matches[0]);
        self::assertSame('bbb', $matches[0]['token']);
    }

    #[Test]
    public function searchFiltersByStatus(): void
    {
        $matches = $this->profiles($this->tool()->search(null, 500));

        self::assertCount(1, $matches);
        self::assertIsArray($matches[0]);
        self::assertSame('ccc', $matches[0]['token']);
    }

    #[Test]
    public function getReturnsASummaryByTokenWithResourceUri(): void
    {
        $summary = $this->decode($this->tool()->get('bbb'));

        self::assertJsonPath($summary, 'token', 'bbb');
        self::assertJsonPath($summary, 'url', '/slow');
        self::assertJsonPath($summary, 'resource_uri', 'typo3-profiler://profile/bbb');
    }

    #[Test]
    public function getReportsAnErrorForUnknownToken(): void
    {
        self::assertJsonHasPath($this->decode($this->tool()->get('does-not-exist')), 'error');
    }

    #[Test]
    public function getReportsAnErrorForAnInvalidToken(): void
    {
        // The profiler's reader rejects traversal-unsafe tokens -> treated as not found.
        self::assertJsonHasPath($this->decode($this->tool()->get('../../etc/passwd')), 'error');
    }

    /**
     * @return array<mixed>
     */
    private function profiles(string $response): array
    {
        $profiles = $this->decode($response)['profiles'];
        self::assertIsArray($profiles);

        return $profiles;
    }

    private function tool(): PerformanceTool
    {
        return new PerformanceTool(new ProfileProvider($this->rootDir));
    }
}
