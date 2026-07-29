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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Mate;

use KonradMichalik\Typo3AiMate\Mate\ProfileProvider;
use KonradMichalik\Typo3AiMate\Tests\Unit\ProfileFixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ProfileProviderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ProfileProviderTest extends TestCase
{
    use ProfileFixtures;

    protected function setUp(): void
    {
        $this->initProfilesDir('typo3-ai-mate-prov-');

        $this->writeProfile('aaa', ['url' => '/', 'status' => 200], 1_000_000_100);
        $this->writeProfile('bbb', ['url' => '/slow', 'status' => 200, 'cache' => ['hit' => false], 'timing' => ['total_ms' => 500], 'queries' => ['count' => 30], 'page' => ['id' => 42], 'duplicate_queries' => [['sql' => 'X', 'count' => 25]]], 1_000_000_200);
        $this->writeProfile('ccc', ['url' => '/error', 'status' => 500], 1_000_000_300);
    }

    protected function tearDown(): void
    {
        $this->cleanupProfilesDir();
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
    }

    #[Test]
    public function rawByTokenRejectsNonAlphanumericTokens(): void
    {
        $provider = new ProfileProvider($this->rootDir);

        // Validated at the trust boundary before reaching the file-based reader.
        self::assertNull($provider->rawByToken('../../etc/passwd'));
        self::assertNull($provider->rawByToken('aaa/../bbb'));
        self::assertNull($provider->rawByToken('aaa.json'));
        self::assertNull($provider->rawByToken(''));
    }

    #[Test]
    public function rawByTokenRedactsUrlAndSqlPii(): void
    {
        $this->writeProfile('ddd', [
            'url' => '/form?email=jane@example.com&token=secret123',
            'queries' => [['sql' => "SELECT * FROM fe_users WHERE email='bob@example.com'", 'ms' => 1.0]],
        ], 1_000_000_400);

        $profile = (new ProfileProvider($this->rootDir))->rawByToken('ddd');

        self::assertIsArray($profile);
        $url = $profile['url'];
        self::assertIsString($url);
        self::assertStringNotContainsString('jane@example.com', $url);
        self::assertStringContainsString('[redacted-email]', $url);
        self::assertStringContainsString('token=[redacted]', $url);

        $queries = $profile['queries'];
        self::assertIsArray($queries);
        $firstQuery = $queries[0];
        self::assertIsArray($firstQuery);
        $sql = $firstQuery['sql'];
        self::assertIsString($sql);
        self::assertStringNotContainsString('bob@example.com', $sql);
        self::assertStringContainsString('[redacted-email]', $sql);
    }

    #[Test]
    public function summarizeRedactsTheUrl(): void
    {
        $summary = (new ProfileProvider($this->rootDir))->summarize(['token' => 'x', 'url' => '/p?token=abc123secret']);

        self::assertSame('/p?token=[redacted]', $summary['url']);
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
    public function summarizeSurfacesTheActivationModeFromTheProvenanceMeta(): void
    {
        $summary = (new ProfileProvider($this->rootDir))->summarize([
            'token' => 'x',
            'meta' => ['activationMode' => 'stateFile', 'applicationContext' => 'Development'],
        ]);

        self::assertSame('stateFile', $summary['activation_mode']);
    }

    #[Test]
    public function summarizeReportsANullActivationModeWhenTheProfileHasNoMeta(): void
    {
        $summary = (new ProfileProvider($this->rootDir))->summarize(['token' => 'x']);

        self::assertNull($summary['activation_mode']);
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
    public function searchStopsAtTheRequestedLimit(): void
    {
        // Both aaa and bbb have status 200; limit 1 keeps only the newest match.
        $matches = (new ProfileProvider($this->rootDir))->search(null, 200, 1);

        self::assertSame(['bbb'], array_column($matches, 'token'));
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
}
