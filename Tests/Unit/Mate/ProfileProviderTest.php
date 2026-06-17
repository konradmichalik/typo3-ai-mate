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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Mate;

use KonradMichalik\Typo3AiMate\Mate\ProfileProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ProfileProviderTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final class ProfileProviderTest extends TestCase
{
    private string $rootDir;
    private string $profilesDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/typo3-ai-mate-prov-'.bin2hex(random_bytes(8));
        $this->profilesDir = $this->rootDir.'/var/log/profiles';
        mkdir($this->profilesDir, 0777, true);

        $this->writeProfile('aaa', ['url' => '/', 'status' => 200], 1_000_000_100);
        $this->writeProfile('bbb', ['url' => '/slow', 'status' => 200, 'cache' => ['hit' => false], 'timing' => ['total_ms' => 500], 'queries' => ['count' => 30], 'page' => ['id' => 42], 'duplicate_queries' => [['sql' => 'X', 'count' => 25]]], 1_000_000_200);
        $this->writeProfile('ccc', ['url' => '/error', 'status' => 500], 1_000_000_300);
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
    public function rawLatestReturnsTheNewestProfile(): void
    {
        $profile = (new ProfileProvider($this->rootDir))->rawLatest();

        self::assertIsArray($profile);
        self::assertSame('ccc', $profile['token']);
    }

    #[Test]
    public function rawByTokenReturnsTheProfileOrNull(): void
    {
        $provider = new ProfileProvider($this->rootDir);

        $profile = $provider->rawByToken('bbb');
        self::assertIsArray($profile);
        self::assertSame('/slow', $profile['url']);

        self::assertNull($provider->rawByToken('unknown'));
        // Traversal-unsafe tokens are rejected by the profiler's reader.
        self::assertNull($provider->rawByToken('../../etc/passwd'));
    }

    #[Test]
    public function summariesAreNewestFirstAndCarryAResourceUri(): void
    {
        $summaries = (new ProfileProvider($this->rootDir))->summaries(10);

        self::assertSame(['ccc', 'bbb', 'aaa'], array_column($summaries, 'token'));
        self::assertSame('typo3-profiler://profile/ccc', $summaries[0]['resource_uri']);
    }

    #[Test]
    public function summarizeExtractsTheTriageFields(): void
    {
        $provider = new ProfileProvider($this->rootDir);
        $profile = $provider->rawByToken('bbb');
        self::assertIsArray($profile);

        $summary = $provider->summarize($profile);

        self::assertFalse($summary['cache_hit']);
        self::assertSame(500, $summary['total_ms']);
        self::assertSame(30, $summary['query_count']);
        self::assertSame(1, $summary['duplicate_queries']);
        self::assertSame(['id' => 42], $summary['page']);
        self::assertSame('typo3-profiler://profile/bbb', $summary['resource_uri']);
    }

    #[Test]
    public function searchFiltersByUrlAndStatus(): void
    {
        $provider = new ProfileProvider($this->rootDir);

        self::assertSame('bbb', $provider->search('/slow', null, 10)[0]['token']);
        self::assertSame('ccc', $provider->search(null, 500, 10)[0]['token']);
        self::assertSame([], $provider->search('/nonexistent', null, 10));
    }

    #[Test]
    public function annotateFlagsAnUnsupportedSchemaVersion(): void
    {
        $provider = new ProfileProvider($this->rootDir);

        self::assertArrayNotHasKey('_schema_warning', $provider->annotate(['schemaVersion' => 1, 'token' => 'x']));
        self::assertArrayHasKey('_schema_warning', $provider->annotate(['schemaVersion' => 99, 'token' => 'x']));
        self::assertArrayHasKey('_schema_warning', $provider->annotate(['token' => 'x']));
    }

    #[Test]
    public function resourceUriUsesTheProfilerScheme(): void
    {
        self::assertSame('typo3-profiler://profile/abc123', (new ProfileProvider($this->rootDir))->resourceUri('abc123'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeProfile(string $token, array $data, int $mtime, ?int $schemaVersion = 1): void
    {
        $base = ['token' => $token, 'time' => '2026-06-15T10:00:00+00:00'];
        if (null !== $schemaVersion) {
            $base['schemaVersion'] = $schemaVersion;
        }
        $file = $this->profilesDir.'/'.$token.'.json';
        file_put_contents($file, json_encode($base + $data, \JSON_THROW_ON_ERROR));
        touch($file, $mtime);
    }
}
