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

        $decoded = $this->decodeJson($output);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Command "%s" did not return valid JSON: %s', $command, $this->excerpt($output)), 1718000101);
        }

        if (isset($decoded['error']) && is_string($decoded['error'])) {
            throw new RuntimeException(sprintf('Command "%s" reported an error: %s', $command, $decoded['error']), 1718000102);
        }

        return $decoded;
    }

    /**
     * Like {@see json()}, but never throws: on failure it returns a structured
     * {"error": "..."} envelope. The MCP tools use this so a command failure
     * (bad bootstrap, missing argument, polluted output) surfaces to the
     * assistant as a readable cause instead of an opaque protocol-level error.
     *
     * @param list<string|int>           $arguments
     * @param array<string, scalar|bool> $options
     *
     * @return array<mixed>
     */
    public function jsonOrError(string $command, array $arguments = [], array $options = []): array
    {
        try {
            return $this->json($command, $arguments, $options);
        } catch (RuntimeException $exception) {
            return ['error' => $exception->getMessage()];
        }
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
     * Decode the command's stdout as JSON, tolerating leading noise. The booted
     * TYPO3 may print PHP warnings, notices or deprecations (e.g. a missing
     * encryptionKey) before the JSON document — those would otherwise corrupt the
     * stream and surface as an opaque MCP error. Our commands emit a single JSON
     * document last, so we retry from its first `{`/`[` if the raw decode fails.
     */
    private function decodeJson(string $output): mixed
    {
        $decoded = json_decode($output, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Retry from the first `{`/`[`, skipping any leading noise.
        $offsets = array_filter(
            [strpos($output, '{'), strpos($output, '[')],
            static fn (int|false $offset): bool => false !== $offset,
        );

        return [] === $offsets ? null : json_decode(substr($output, min($offsets)), true);
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
