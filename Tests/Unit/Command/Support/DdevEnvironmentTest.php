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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command\Support;

use KonradMichalik\Typo3AiMate\Command\Support\DdevEnvironment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DdevEnvironmentTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class DdevEnvironmentTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir().'/ai-mate-ddev-'.bin2hex(random_bytes(8));
        mkdir($this->projectRoot, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    #[Test]
    public function detectReportsNoDdevProjectWithoutConfigFile(): void
    {
        // Pin isInsideContainer explicitly - this test is about isDdevProject
        // only, and must not depend on the real IS_DDEV_PROJECT env value of
        // whatever machine runs the suite (e.g. inside a DDEV web container).
        $environment = DdevEnvironment::detect($this->projectRoot, 'false');

        self::assertFalse($environment->isDdevProject);
        self::assertFalse($environment->isInsideContainer);
    }

    #[Test]
    public function detectReportsDdevProjectFromConfigFile(): void
    {
        mkdir($this->projectRoot.'/.ddev', 0o700, true);
        file_put_contents($this->projectRoot.'/.ddev/config.yaml', "name: example\n");

        $environment = DdevEnvironment::detect($this->projectRoot);

        self::assertTrue($environment->isDdevProject);
    }

    #[Test]
    public function detectReportsInsideContainerFromEnvironmentVariable(): void
    {
        $environment = DdevEnvironment::detect($this->projectRoot, 'true');

        self::assertTrue($environment->isInsideContainer);
    }

    #[Test]
    public function detectReportsOutsideContainerForAnyOtherEnvironmentValue(): void
    {
        $environment = DdevEnvironment::detect($this->projectRoot, 'false');

        self::assertFalse($environment->isInsideContainer);
    }

    #[Test]
    public function launchCommandUsesDdevExecFormForDdevProject(): void
    {
        mkdir($this->projectRoot.'/.ddev', 0o700, true);
        file_put_contents($this->projectRoot.'/.ddev/config.yaml', "name: example\n");

        $command = DdevEnvironment::detect($this->projectRoot)->mcpServerLaunchCommand();

        self::assertSame(['command' => 'ddev', 'args' => ['exec', 'vendor/bin/mate', 'serve']], $command);
    }

    #[Test]
    public function launchCommandUsesHostFormForNonDdevProject(): void
    {
        $command = DdevEnvironment::detect($this->projectRoot)->mcpServerLaunchCommand();

        self::assertSame(['command' => './vendor/bin/mate', 'args' => ['serve']], $command);
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
