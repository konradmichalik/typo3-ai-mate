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

use KonradMichalik\Typo3AiMate\Command\Support\MateCliRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MateCliRunnerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class MateCliRunnerTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir().'/ai-mate-runner-'.bin2hex(random_bytes(8));
        mkdir($this->projectRoot.'/vendor/bin', 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    #[Test]
    public function binaryExistsReflectsFilePresence(): void
    {
        $runner = new MateCliRunner($this->projectRoot);

        self::assertFalse($runner->binaryExists());

        file_put_contents($this->projectRoot.'/vendor/bin/mate', '<?php exit(0);');

        self::assertTrue($runner->binaryExists());
    }

    #[Test]
    public function runExecutesTheMateBinaryWithArguments(): void
    {
        file_put_contents(
            $this->projectRoot.'/vendor/bin/mate',
            '<?php fwrite(STDOUT, implode(" ", array_slice($argv, 1)));',
        );

        $process = (new MateCliRunner($this->projectRoot))->run('discover', ['--composer']);

        self::assertTrue($process->isSuccessful());
        self::assertSame('discover --composer', $process->getOutput());
    }

    #[Test]
    public function runReportsFailingExitCode(): void
    {
        file_put_contents($this->projectRoot.'/vendor/bin/mate', '<?php exit(1);');

        $process = (new MateCliRunner($this->projectRoot))->run('init');

        self::assertFalse($process->isSuccessful());
        self::assertSame(1, $process->getExitCode());
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
