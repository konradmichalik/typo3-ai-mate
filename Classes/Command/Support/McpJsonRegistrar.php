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

use stdClass;

use function fclose;
use function flock;
use function fopen;
use function fseek;
use function ftruncate;
use function fwrite;
use function sprintf;
use function stream_get_contents;

/**
 * McpJsonRegistrar.
 *
 * Merges a single `typo3-ai-mate` server entry into a harness configuration
 * file, preserving every other entry (including one `mate init` itself may have
 * written) untouched. The section key differs per harness (`mcpServers` for
 * Claude Code, `mcp` for opencode), the merge does not. Never reached for the
 * instruction artifacts (`mate/AGENT_INSTRUCTIONS.md`, the `AGENTS.md` managed
 * block) — those stay `mate discover`'s job.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class McpJsonRegistrar
{
    private const SERVER_NAME = 'typo3-ai-mate';

    public function __construct(
        private string $path,
        private string $sectionKey = 'mcpServers',
    ) {}

    /**
     * @param array<string, mixed> $serverEntry
     *
     * @return array{action: 'created'|'updated'|'unchanged', error?: string}
     */
    public function register(array $serverEntry, bool $dryRun = false): array
    {
        if ($dryRun) {
            return $this->planAction($serverEntry);
        }

        $fileExisted = file_exists($this->path);
        $handle = @fopen($this->path, 'c+');
        if (false === $handle) {
            return ['action' => 'unchanged', 'error' => sprintf('Could not open %s.', $this->path)];
        }

        try {
            // The lock guards the whole read-merge-write sequence on this exact
            // file handle/inode, so a concurrent installer run cannot read the
            // same pre-write state and then overwrite this one's result (each
            // waits for the other's lock before reading).
            if (!flock($handle, \LOCK_EX)) {
                return ['action' => 'unchanged', 'error' => sprintf('Could not lock %s.', $this->path)];
            }

            $contents = stream_get_contents($handle);
            $document = $this->decodeContents(false !== $contents ? $contents : '');
            if (isset($document['error'])) {
                return ['action' => 'unchanged', 'error' => $document['error']];
            }

            [$action, $data] = $this->merge($document['data'], $serverEntry, $fileExisted);
            if ('unchanged' === $action) {
                return ['action' => 'unchanged'];
            }

            return $this->writeLocked($handle, $data) ? ['action' => $action] : ['action' => 'unchanged', 'error' => sprintf('Failed to write %s.', $this->path)];
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param array<string, mixed> $serverEntry
     *
     * @return array{action: 'created'|'updated'|'unchanged', error?: string}
     */
    private function planAction(array $serverEntry): array
    {
        $fileExisted = file_exists($this->path);
        $contents = $fileExisted ? file_get_contents($this->path) : '';
        $document = $this->decodeContents(false !== $contents ? $contents : '');
        if (isset($document['error'])) {
            return ['action' => 'unchanged', 'error' => $document['error']];
        }

        [$action] = $this->merge($document['data'], $serverEntry, $fileExisted);

        return ['action' => $action];
    }

    /**
     * @param array<string, mixed> $serverEntry
     *
     * @return array{0: 'created'|'updated'|'unchanged', 1: stdClass}
     */
    private function merge(stdClass $document, array $serverEntry, bool $fileExisted): array
    {
        $existingSection = $document->{$this->sectionKey} ?? null;
        $section = $existingSection instanceof stdClass ? $existingSection : new stdClass();
        $entry = (object) $serverEntry;

        if (json_encode($section->{self::SERVER_NAME} ?? null) === json_encode($entry)) {
            return ['unchanged', $document];
        }

        $section->{self::SERVER_NAME} = $entry;
        $document->{$this->sectionKey} = $section;

        return [$fileExisted ? 'updated' : 'created', $document];
    }

    /**
     * @return array{data: stdClass, error?: string}
     */
    private function decodeContents(string $contents): array
    {
        // Decoded into objects rather than arrays: an empty JSON object anywhere
        // else in the file would otherwise come back as [] and be written back as
        // [], which opencode refuses to start on.
        $decoded = '' === trim($contents) ? new stdClass() : json_decode($contents);
        if (!$decoded instanceof stdClass) {
            return ['data' => new stdClass(), 'error' => sprintf('%s contains invalid JSON; leaving it untouched.', $this->path)];
        }

        return ['data' => $decoded];
    }

    /**
     * @param resource $handle a handle already positioned anywhere in the file, held under an exclusive lock
     */
    private function writeLocked($handle, stdClass $data): bool
    {
        $encoded = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if (false === $encoded) {
            return false;
        }

        if (!ftruncate($handle, 0) || -1 === fseek($handle, 0)) {
            return false;
        }

        return false !== fwrite($handle, $encoded."\n");
    }
}
