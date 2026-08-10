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

use function is_array;
use function sprintf;

/**
 * McpJsonRegistrar.
 *
 * Merges a single `mcpServers.typo3-ai-mate` entry into the project's
 * `.mcp.json`, preserving every other entry (including one `mate init` itself
 * may have written) untouched. Never reached for the instruction artifacts
 * (`mate/AGENT_INSTRUCTIONS.md`, the `AGENTS.md` managed block) — those stay
 * `mate discover`'s job.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class McpJsonRegistrar
{
    private const SERVER_NAME = 'typo3-ai-mate';

    public function __construct(private string $path) {}

    /**
     * @param array{command: string, args: list<string>} $serverEntry
     *
     * @return array{action: 'created'|'updated'|'unchanged', error?: string}
     */
    public function register(array $serverEntry, bool $dryRun = false): array
    {
        $fileExisted = file_exists($this->path);

        $document = $this->readDocument();
        if (isset($document['error'])) {
            return ['action' => 'unchanged', 'error' => $document['error']];
        }

        $servers = is_array($document['data']['mcpServers'] ?? null) ? $document['data']['mcpServers'] : [];
        if (($servers[self::SERVER_NAME] ?? null) === $serverEntry) {
            return ['action' => 'unchanged'];
        }

        $servers[self::SERVER_NAME] = $serverEntry;
        $data = $document['data'];
        $data['mcpServers'] = $servers;
        $action = $fileExisted ? 'updated' : 'created';

        if ($dryRun) {
            return ['action' => $action];
        }

        return $this->write($data) ? ['action' => $action] : ['action' => 'unchanged', 'error' => sprintf('Failed to write %s.', $this->path)];
    }

    /**
     * @return array{data: array<int|string, mixed>, error?: string}
     */
    private function readDocument(): array
    {
        if (!file_exists($this->path)) {
            return ['data' => []];
        }

        $contents = file_get_contents($this->path);
        if (false === $contents) {
            return ['data' => [], 'error' => sprintf('Could not read %s.', $this->path)];
        }

        $decoded = '' === trim($contents) ? [] : json_decode($contents, true);
        if (!is_array($decoded)) {
            return ['data' => [], 'error' => sprintf('%s contains invalid JSON; leaving it untouched.', $this->path)];
        }

        return ['data' => $decoded];
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function write(array $data): bool
    {
        $encoded = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

        return false !== $encoded && false !== file_put_contents($this->path, $encoded."\n");
    }
}
