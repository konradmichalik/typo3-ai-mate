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
}
