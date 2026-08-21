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

use KonradMichalik\Typo3AiMate\Mate\{ProfileProvider, ProfilerStateProvider, SiteHostsProvider, ToolDescriptionComputer, Typo3CliRunner};
use KonradMichalik\Typo3AiMate\Support\RuntimeArtifacts;
use KonradMichalik\Typo3AiMate\Tests\Unit\ProfileFixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ToolDescriptionComputerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ToolDescriptionComputerTest extends TestCase
{
    use ProfileFixtures;

    protected function setUp(): void
    {
        $this->initProfilesDir('typo3-ai-mate-desc-');
    }

    protected function tearDown(): void
    {
        $this->cleanupProfilesDir();
    }

    #[Test]
    public function leavesAnUnrelatedToolDescriptionUnchanged(): void
    {
        self::assertSame('Resolved TCA.', $this->computer()->compute('typo3-tca', 'Resolved TCA.'));
    }

    #[Test]
    public function pointsAtProfilerStartWhenNoProfileExistsYet(): void
    {
        $description = $this->computer()->compute('typo3-profiler-latest', 'Latest profile.');

        self::assertStringContainsString('Latest profile.', $description);
        self::assertStringContainsString('typo3-profiler-start', $description);
    }

    #[Test]
    public function statesTheNewestProfileTimeWhenProfilesExist(): void
    {
        $this->writeProfile('aaa', ['url' => '/'], 1_000_000_100);

        $description = $this->computer()->compute('typo3-profiler-list', 'List profiles.');

        self::assertStringContainsString('2026-06-15T10:00:00+00:00', $description);
    }

    #[Test]
    public function statesProfilingIsInactive(): void
    {
        $description = $this->computer()->compute('typo3-profiler-start', 'Start profiling.');

        self::assertStringContainsString('not currently active', $description);
    }

    #[Test]
    public function statesTheRemainingProfilingWindow(): void
    {
        file_put_contents($this->rootDir.'/var/log/profiler-activation-state.json', json_encode(['expiresAt' => time() + 300]));

        $description = $this->computer()->compute('typo3-profiler-status', 'Profiler status.');

        self::assertStringContainsString('remaining', $description);
    }

    #[Test]
    public function statesTheAllowedRenderPageHosts(): void
    {
        mkdir($this->rootDir.'/config/sites/main', 0o700, true);
        file_put_contents($this->rootDir.'/config/sites/main/config.yaml', "base: 'https://example.test/'\n");

        $description = $this->computer()->compute('typo3-render-page', 'Render a page.');

        self::assertStringContainsString('example.test', $description);
    }

    #[Test]
    public function statesNoHostsAreAllowedWithoutAnySiteConfiguration(): void
    {
        $description = $this->computer()->compute('typo3-render-page', 'Render a page.');

        self::assertStringContainsString('no host is currently allowed', $description);
    }

    private function computer(): ToolDescriptionComputer
    {
        return new ToolDescriptionComputer(
            new ProfileProvider($this->rootDir),
            new ProfilerStateProvider(new Typo3CliRunner($this->rootDir), $this->rootDir),
            new SiteHostsProvider($this->rootDir),
            new RuntimeArtifacts($this->rootDir),
        );
    }
}
