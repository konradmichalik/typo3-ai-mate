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

use KonradMichalik\Typo3AiMate\Mate\ProfileProvider;
use KonradMichalik\Typo3AiMate\Mcp\ProfileResource;
use KonradMichalik\Typo3AiMate\Tests\Unit\ProfileFixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ProfileResourceTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ProfileResourceTest extends TestCase
{
    use DecodesResponses;
    use ProfileFixtures;

    protected function setUp(): void
    {
        $this->initProfilesDir('typo3-ai-mate-res-');

        $this->writeProfile('bbb', ['url' => '/slow', 'queries' => ['count' => 30], 'duplicate_queries' => [['sql' => 'X']]], 1);
        $this->writeProfile('ddd', ['url' => '/old'], 1, 99);
    }

    protected function tearDown(): void
    {
        $this->cleanupProfilesDir();
    }

    #[Test]
    public function profileReturnsTheFullProfileAsAResource(): void
    {
        $result = $this->resource()->profile('bbb');

        self::assertSame('typo3-profiler://profile/bbb', $result['uri']);
        self::assertSame('text/plain', $result['mimeType']);

        $profile = $this->decode($result['text']);
        self::assertSame('/slow', $profile['url']);
        self::assertArrayHasKey('duplicate_queries', $profile);
        self::assertArrayNotHasKey('_schema_warning', $profile);
    }

    #[Test]
    public function profileFlagsAnUnsupportedSchemaVersion(): void
    {
        $profile = $this->decode($this->resource()->profile('ddd')['text']);

        self::assertArrayHasKey('_schema_warning', $profile);
    }

    #[Test]
    public function profileReportsAnErrorForUnknownToken(): void
    {
        self::assertArrayHasKey('error', $this->decode($this->resource()->profile('unknown')['text']));
    }

    #[Test]
    public function sectionReturnsASingleSection(): void
    {
        $payload = $this->decode($this->resource()->section('bbb', 'queries')['text']);

        self::assertArrayHasKey('queries', $payload);
        self::assertIsArray($payload['queries']);
        self::assertSame(30, $payload['queries']['count']);
    }

    #[Test]
    public function sectionReportsAnErrorForAMissingSection(): void
    {
        self::assertArrayHasKey('error', $this->decode($this->resource()->section('bbb', 'nope')['text']));
    }

    #[Test]
    public function sectionReportsAnErrorForUnknownToken(): void
    {
        self::assertArrayHasKey('error', $this->decode($this->resource()->section('unknown', 'queries')['text']));
    }

    private function resource(): ProfileResource
    {
        return new ProfileResource(new ProfileProvider($this->rootDir));
    }
}
