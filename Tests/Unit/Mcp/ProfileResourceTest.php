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
use KonradMichalik\Typo3AiMate\Mcp\ProfileResource;
use KonradMichalik\Typo3AiMate\Tests\Unit\ProfileFixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ProfileResourceTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ProfileResourceTest extends TestCase
{
    use DecodesResponses;
    use JsonAssertions;
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
        self::assertJsonPath($profile, 'url', '/slow');
        self::assertJsonHasPath($profile, 'duplicate_queries');
        self::assertArrayNotHasKey('_schema_warning', $profile);
    }

    #[Test]
    public function profileFlagsAnUnsupportedSchemaVersion(): void
    {
        $profile = $this->decode($this->resource()->profile('ddd')['text']);

        self::assertJsonHasPath($profile, '_schema_warning');
    }

    #[Test]
    public function profileReportsAnErrorForUnknownToken(): void
    {
        self::assertJsonHasPath($this->decode($this->resource()->profile('unknown')['text']), 'error');
    }

    #[Test]
    public function sectionReturnsASingleSection(): void
    {
        $payload = $this->decode($this->resource()->section('bbb', 'queries')['text']);

        self::assertJsonHasPath($payload, 'queries');
        self::assertJsonPath($payload, 'queries.count', 30);
    }

    #[Test]
    public function sectionReportsAnErrorForAMissingSection(): void
    {
        self::assertJsonHasPath($this->decode($this->resource()->section('bbb', 'nope')['text']), 'error');
    }

    #[Test]
    public function sectionReportsAnErrorForUnknownToken(): void
    {
        self::assertJsonHasPath($this->decode($this->resource()->section('unknown', 'queries')['text']), 'error');
    }

    private function resource(): ProfileResource
    {
        return new ProfileResource(new ProfileProvider($this->rootDir));
    }
}
