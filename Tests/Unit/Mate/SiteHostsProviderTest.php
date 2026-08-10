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

use KonradMichalik\Typo3AiMate\Mate\SiteHostsProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SiteHostsProviderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class SiteHostsProviderTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/ai-mate-sites-'.bin2hex(random_bytes(8));
        mkdir($this->rootDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    #[Test]
    public function hostsReturnsAnEmptyListWithoutAnySiteConfiguration(): void
    {
        self::assertSame([], (new SiteHostsProvider($this->rootDir))->hosts());
    }

    #[Test]
    public function hostsReadsTheBaseOfEverySiteConfigFile(): void
    {
        $this->writeSiteConfig('main', "base: 'https://example.test/'\n");
        $this->writeSiteConfig('shop', "base: 'https://shop.example.test/'\n");

        self::assertSame(['example.test', 'shop.example.test'], (new SiteHostsProvider($this->rootDir))->hosts());
    }

    #[Test]
    public function hostsDeduplicatesAndSortsHosts(): void
    {
        $this->writeSiteConfig('main', "base: 'https://example.test/'\n");
        $this->writeSiteConfig('main-en', "base: 'https://example.test/en/'\n");

        self::assertSame(['example.test'], (new SiteHostsProvider($this->rootDir))->hosts());
    }

    #[Test]
    public function hostsIgnoresAConfigFileWithoutABase(): void
    {
        $this->writeSiteConfig('broken', "rootPageId: 1\n");

        self::assertSame([], (new SiteHostsProvider($this->rootDir))->hosts());
    }

    #[Test]
    public function hostsIgnoresInvalidYaml(): void
    {
        $this->writeSiteConfig('broken', "base: [unterminated\n");

        self::assertSame([], (new SiteHostsProvider($this->rootDir))->hosts());
    }

    private function writeSiteConfig(string $identifier, string $contents): void
    {
        $directory = $this->rootDir.'/config/sites/'.$identifier;
        mkdir($directory, 0o700, true);
        file_put_contents($directory.'/config.yaml', $contents);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $absolute = $path.'/'.$entry;
            is_dir($absolute) ? $this->removeDirectory($absolute) : unlink($absolute);
        }

        rmdir($path);
    }
}
