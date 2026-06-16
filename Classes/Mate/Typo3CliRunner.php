<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <km@move-elevator.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Mate;

use RuntimeException;
use Symfony\Component\Process\Process;

use function is_array;
use function is_string;
use function sprintf;

/**
 * Typo3CliRunner.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final readonly class Typo3CliRunner
{
    private const TIMEOUT_SECONDS = 120;

    public function __construct(private string $rootDir) {}

    /**
     * Run a TYPO3 console command and decode its stdout as a JSON array.
     *
     * @param list<string|int>           $arguments positional arguments
     * @param array<string, scalar|bool> $options   --key value pairs; a true bool becomes a bare --flag
     *
     * @return array<mixed>
     */
    public function json(string $command, array $arguments = [], array $options = []): array
    {
        $output = $this->run($command, $arguments, $options);

        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Command "%s" did not return valid JSON: %s', $command, $this->excerpt($output)), 1718000101);
        }

        if (isset($decoded['error']) && is_string($decoded['error'])) {
            throw new RuntimeException(sprintf('Command "%s" reported an error: %s', $command, $decoded['error']), 1718000102);
        }

        return $decoded;
    }

    /**
     * @param list<string|int>           $arguments
     * @param array<string, scalar|bool> $options
     */
    public function run(string $command, array $arguments = [], array $options = []): string
    {
        $process = new Process(
            $this->buildCommandLine($command, $arguments, $options),
            $this->rootDir,
            ['TYPO3_CONTEXT' => 'Development'],
        );
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(sprintf('TYPO3 command "%s" failed (exit %s): %s', $command, (string) $process->getExitCode(), trim($process->getErrorOutput()) ?: $this->excerpt($process->getOutput())), 1718000103);
        }

        return $process->getOutput();
    }

    /**
     * @param list<string|int>           $arguments
     * @param array<string, scalar|bool> $options
     *
     * @return list<string>
     */
    private function buildCommandLine(string $command, array $arguments, array $options): array
    {
        $line = [\PHP_BINARY, $this->rootDir.'/vendor/bin/typo3', $command];

        foreach ($arguments as $argument) {
            $line[] = (string) $argument;
        }

        foreach ($options as $name => $value) {
            if (false === $value) {
                continue;
            }
            if (true === $value) {
                $line[] = '--'.$name;
                continue;
            }
            $line[] = '--'.$name;
            $line[] = (string) $value;
        }

        return $line;
    }

    private function excerpt(string $value): string
    {
        $value = trim($value);

        return mb_strlen($value) > 500 ? mb_substr($value, 0, 500).'…' : $value;
    }
}
