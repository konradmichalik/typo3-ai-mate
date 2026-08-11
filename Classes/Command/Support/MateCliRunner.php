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

namespace KonradMichalik\Typo3AiMate\Command\Support;

use Symfony\Component\Process\Process;

/**
 * MateCliRunner.
 *
 * Mirrors {@see \KonradMichalik\Typo3AiMate\Mate\Typo3CliRunner}, but in the
 * opposite direction: this shells out from the booted TYPO3 process to the
 * `mate` binary, so `typo3-ai-mate:install` can drive `mate init`/`mate
 * discover` without depending on the Mate process's own DI container.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class MateCliRunner
{
    private const TIMEOUT_SECONDS = 120;

    /**
     * @param float $timeoutSeconds overrides the process timeout; test-only seam
     */
    public function __construct(
        private string $projectRoot,
        private float $timeoutSeconds = self::TIMEOUT_SECONDS,
    ) {}

    public function binaryExists(): bool
    {
        return is_file($this->projectRoot.'/vendor/bin/mate');
    }

    /**
     * @param list<string> $arguments
     */
    public function run(string $command, array $arguments = []): Process
    {
        $process = new Process(
            [\PHP_BINARY, $this->projectRoot.'/vendor/bin/mate', $command, ...$arguments],
            $this->projectRoot,
        );
        $process->setTimeout($this->timeoutSeconds);
        $process->run();

        return $process;
    }
}
