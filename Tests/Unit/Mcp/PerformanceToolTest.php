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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Mcp;

use KonradMichalik\Typo3AiMate\Mcp\PerformanceTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PerformanceToolTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final class PerformanceToolTest extends TestCase
{
    private string $rootDir;
    private string $profilesDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/typo3-ai-mate-perf-'.bin2hex(random_bytes(8));
        $this->profilesDir = $this->rootDir.'/var/log/profiles';
        mkdir($this->profilesDir, 0777, true);

        // Three profiles with increasing mtime so order is deterministic.
        $this->writeProfile('aaa', ['url' => '/', 'status' => 200, 'cache' => ['hit' => true], 'timing' => ['total_ms' => 10], 'queries' => ['count' => 2]], 1_000_000_100);
        $this->writeProfile('bbb', ['url' => '/slow', 'status' => 200, 'cache' => ['hit' => false], 'timing' => ['total_ms' => 500], 'queries' => ['count' => 30], 'page' => ['id' => 42, 'type' => 0], 'duplicate_queries' => [['sql' => 'SELECT ...', 'count' => 25]]], 1_000_000_200);
        $this->writeProfile('ccc', ['url' => '/error', 'status' => 500, 'cache' => ['hit' => false], 'timing' => ['total_ms' => 80], 'queries' => ['count' => 5]], 1_000_000_300);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->profilesDir.'/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->profilesDir);
        @rmdir($this->rootDir.'/var/log');
        @rmdir($this->rootDir.'/var');
        @rmdir($this->rootDir);
    }

    #[Test]
    public function latestReturnsTheNewestProfile(): void
    {
        $profile = (new PerformanceTool($this->rootDir))->latest();

        self::assertSame('ccc', $profile['token']);
        self::assertSame('/error', $profile['url']);
        self::assertSame(500, $profile['status']);
    }

    #[Test]
    public function latestReportsAnErrorWhenNoProfilesExist(): void
    {
        $empty = sys_get_temp_dir().'/typo3-ai-mate-empty-'.bin2hex(random_bytes(8));
        $result = (new PerformanceTool($empty))->latest();

        self::assertArrayHasKey('error', $result);
    }

    #[Test]
    public function listReturnsSummariesNewestFirst(): void
    {
        $list = (new PerformanceTool($this->rootDir))->list()['profiles'];

        self::assertCount(3, $list);
        self::assertSame(['ccc', 'bbb', 'aaa'], array_column($list, 'token'));

        $slow = $list[1];
        self::assertSame('/slow', $slow['url']);
        self::assertFalse($slow['cache_hit']);
        self::assertSame(500, $slow['total_ms'] ?? null);
        self::assertSame(30, $slow['query_count']);
        self::assertSame(1, $slow['duplicate_queries']);
        self::assertSame(['id' => 42, 'type' => 0], $slow['page']);
    }

    #[Test]
    public function listRespectsTheLimit(): void
    {
        $list = (new PerformanceTool($this->rootDir))->list(1)['profiles'];

        self::assertCount(1, $list);
        self::assertSame('ccc', $list[0]['token']);
    }

    #[Test]
    public function searchFiltersByUrlSubstring(): void
    {
        $matches = (new PerformanceTool($this->rootDir))->search('/slow')['profiles'];

        self::assertCount(1, $matches);
        self::assertSame('bbb', $matches[0]['token']);
    }

    #[Test]
    public function searchFiltersByStatus(): void
    {
        $matches = (new PerformanceTool($this->rootDir))->search(null, 500)['profiles'];

        self::assertCount(1, $matches);
        self::assertSame('ccc', $matches[0]['token']);
    }

    #[Test]
    public function getReturnsTheFullProfileByToken(): void
    {
        $profile = (new PerformanceTool($this->rootDir))->get('bbb');

        self::assertSame('bbb', $profile['token']);
        self::assertSame('/slow', $profile['url']);
        self::assertArrayHasKey('duplicate_queries', $profile);
    }

    #[Test]
    public function getReportsAnErrorForUnknownToken(): void
    {
        self::assertArrayHasKey('error', (new PerformanceTool($this->rootDir))->get('does-not-exist'));
    }

    #[Test]
    public function getRejectsInvalidTokens(): void
    {
        $result = (new PerformanceTool($this->rootDir))->get('../../etc/passwd');

        self::assertSame('Invalid token.', $result['error'] ?? null);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeProfile(string $token, array $data, int $mtime): void
    {
        $file = $this->profilesDir.'/'.$token.'.json';
        file_put_contents($file, json_encode(['token' => $token, 'time' => '2026-06-15T10:00:00+00:00'] + $data, \JSON_THROW_ON_ERROR));
        touch($file, $mtime);
    }
}
