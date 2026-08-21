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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Support;

use KonradMichalik\Typo3AiMate\Support\RuntimeArtifacts;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RuntimeArtifactsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RuntimeArtifactsTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/ai-mate-artifacts-'.bin2hex(random_bytes(8));
        mkdir($this->rootDir.'/var/log', 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->rootDir.'/var/log/profiles/*') ?: [] as $entry) {
            is_dir($entry) ? rmdir($entry) : unlink($entry);
        }
        if (is_dir($this->rootDir.'/var/log/profiles')) {
            rmdir($this->rootDir.'/var/log/profiles');
        }
        foreach (glob($this->rootDir.'/var/log/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->rootDir.'/var/log');
        rmdir($this->rootDir.'/var');
        rmdir($this->rootDir);
    }

    #[Test]
    public function reportsNoEntriesForAnInstallationThatHasNotLoggedAnything(): void
    {
        self::assertFalse((new RuntimeArtifacts($this->rootDir))->hasLogEntries());
    }

    #[Test]
    public function anEmptyLogFileIsNotAnEntry(): void
    {
        touch($this->rootDir.'/var/log/typo3_example.log');

        self::assertFalse((new RuntimeArtifacts($this->rootDir))->hasLogEntries());
    }

    #[Test]
    public function reportsEntriesAsSoonAsOneLogFileHasContent(): void
    {
        touch($this->rootDir.'/var/log/typo3_empty.log');
        file_put_contents($this->rootDir.'/var/log/typo3_example.log', "something happened\n");

        self::assertTrue((new RuntimeArtifacts($this->rootDir))->hasLogEntries());
    }

    #[Test]
    public function ignoresNonLogFilesInTheSameDirectory(): void
    {
        file_put_contents($this->rootDir.'/var/log/profiler-activation-state.json', '{"expiresAt":1}');

        self::assertFalse((new RuntimeArtifacts($this->rootDir))->hasLogEntries());
    }

    #[Test]
    public function reportsNoProfilesForAnInstallationThatHasNotRecordedAny(): void
    {
        self::assertFalse((new RuntimeArtifacts($this->rootDir))->hasProfiles());
    }

    #[Test]
    public function aDirectoryNamedLikeAProfileIsNotAProfile(): void
    {
        mkdir($this->profileDir().'/20260821.json', 0o700, true);

        self::assertFalse((new RuntimeArtifacts($this->rootDir))->hasProfiles());
    }

    #[Test]
    public function anEmptyProfileFileIsNotAProfile(): void
    {
        // The profiler tools read the file; a zero-byte one gates them open with
        // nothing to read, which is the inconsistent state to avoid.
        touch($this->profileDir().'/empty.json');

        self::assertFalse((new RuntimeArtifacts($this->rootDir))->hasProfiles());
    }

    #[Test]
    public function reportsProfilesAsSoonAsOneReadableProfileHasContent(): void
    {
        touch($this->profileDir().'/empty.json');
        file_put_contents($this->profileDir().'/abc123.json', '{"token":"abc123"}');

        self::assertTrue((new RuntimeArtifacts($this->rootDir))->hasProfiles());
    }

    private function profileDir(): string
    {
        $dir = $this->rootDir.'/var/log/profiles';
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }

        return $dir;
    }
}
