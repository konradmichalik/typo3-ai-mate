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

        self::assertJsonPath($this->decode($empty->latest()), 'unsupported', true);
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
        self::assertJsonPath($this->decode($this->tool()->get('does-not-exist')), 'unsupported', true);
    }

    #[Test]
    public function getReportsAnErrorForAnInvalidToken(): void
    {
        // The profiler's reader rejects traversal-unsafe tokens -> treated as not found.
        self::assertJsonPath($this->decode($this->tool()->get('../../etc/passwd')), 'unsupported', true);
    }

    #[Test]
    public function compareNamesTheLargestDifferencesBetweenTwoFixtureProfiles(): void
    {
        $comparison = $this->decode($this->tool()->compare('aaa', 'bbb'));

        self::assertJsonPath($comparison, 'a.token', 'aaa');
        self::assertJsonPath($comparison, 'b.token', 'bbb');
        self::assertJsonPath($comparison, 'b.resource_uri', 'typo3-profiler://profile/bbb');
        self::assertJsonPath($comparison, 'largest_differences.0.metric', 'timing.total_ms');
        self::assertJsonPath($comparison, 'largest_differences.0.delta', 490);
        self::assertJsonPath($comparison, 'cache.b.hit', false);
        self::assertJsonPath($comparison, 'contributors.duplicate_queries.0.delta_count', 25);
    }

    #[Test]
    public function compareRedactsTheRequestUrls(): void
    {
        $this->writeProfile('ddd', ['url' => '/form?token=secret123'], 1_000_000_400);

        self::assertJsonPath($this->decode($this->tool()->compare('aaa', 'ddd')), 'b.url', '/form?token=[redacted]');
    }

    #[Test]
    public function compareReportsAnErrorWhenEitherProfileIsUnknown(): void
    {
        self::assertJsonPath($this->decode($this->tool()->compare('aaa', 'does-not-exist')), 'unsupported', true);
        self::assertJsonPath($this->decode($this->tool()->compare('../../etc/passwd', 'aaa')), 'unsupported', true);
    }

    #[Test]
    public function triageRanksTheProfilesWorthALookWithTheirReasons(): void
    {
        $triage = $this->decode($this->tool()->triage());
        $ranked = $this->profiles($this->tool()->triage());

        self::assertJsonPath($triage, 'considered', 3);
        self::assertJsonPath($triage, 'flagged', 2);
        self::assertSame(['ccc', 'bbb'], array_column($ranked, 'token'));
        self::assertIsArray($ranked[0]);
        self::assertSame(['HTTP 500'], $ranked[0]['reasons']);
        self::assertSame('typo3-profiler://profile/ccc', $ranked[0]['resource_uri']);
        self::assertIsArray($ranked[1]);
        self::assertSame([
            '500 ms, 6.3x the median of 3 profiles',
            '30 queries, 6.0x the median of 3 profiles',
            'N+1 suspect: one query ran 25 times',
        ], $ranked[1]['reasons']);
    }

    #[Test]
    public function triageCapsTheRankedListAndSaysSo(): void
    {
        for ($i = 0; $i < 7; ++$i) {
            $this->writeProfile('err'.$i, ['url' => '/e', 'status' => 500], 1_000_000_400 + $i);
        }

        $triage = $this->decode($this->tool()->triage());

        self::assertJsonPath($triage, 'flagged', 9);
        self::assertJsonPath($triage, '_truncated', true);
        self::assertCount(5, $this->profiles($this->tool()->triage()));
    }

    #[Test]
    public function triageReportsAnErrorWhenNoProfilesExist(): void
    {
        $empty = new PerformanceTool(new ProfileProvider(sys_get_temp_dir().'/typo3-ai-mate-empty-'.bin2hex(random_bytes(8))));

        self::assertJsonPath($this->decode($empty->triage()), 'unsupported', true);
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
