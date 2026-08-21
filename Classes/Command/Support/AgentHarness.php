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

use function array_slice;

/**
 * AgentHarness.
 *
 * The assistant harnesses this package can register its MCP server for. They
 * disagree on both the file and the entry shape, which is why registering for
 * one of them leaves the others with instructions for tools they cannot call.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
enum AgentHarness: string
{
    case Claude = 'claude';
    case Opencode = 'opencode';

    /**
     * Project-relative file the harness reads its MCP servers from.
     */
    public function configFile(): string
    {
        return match ($this) {
            self::Claude => '.mcp.json',
            self::Opencode => 'opencode.json',
        };
    }

    /**
     * Top-level key holding the server map.
     */
    public function sectionKey(): string
    {
        return match ($this) {
            self::Claude => 'mcpServers',
            self::Opencode => 'mcp',
        };
    }

    /**
     * @param list<string> $argv the launch command, program first
     *
     * @return array<string, mixed>
     */
    public function serverEntry(array $argv): array
    {
        return match ($this) {
            // Claude Code splits the program from its arguments.
            self::Claude => ['command' => $argv[0] ?? '', 'args' => array_slice($argv, 1)],
            // opencode takes one argv array and needs the transport named
            // explicitly; without enabled=true the server is registered but not
            // started.
            self::Opencode => ['type' => 'local', 'command' => $argv, 'enabled' => true],
        };
    }

    /**
     * Harnesses this project shows evidence of. An empty result means nothing
     * was recognisable, which the caller reads as "register for all of them"
     * rather than as "register for none".
     *
     * @return list<self>
     */
    public static function detect(string $projectRoot): array
    {
        $detected = [];
        foreach (self::cases() as $harness) {
            foreach ($harness->markers() as $marker) {
                if (file_exists($projectRoot.'/'.$marker)) {
                    $detected[] = $harness;
                    continue 2;
                }
            }
        }

        return $detected;
    }

    /**
     * Paths that only exist because someone drives this project with that
     * harness. Deliberately excludes `AGENTS.md` and `.agents/`, which `mate`
     * writes for every harness and therefore prove nothing.
     *
     * @return list<string>
     */
    private function markers(): array
    {
        return match ($this) {
            self::Claude => ['.mcp.json', '.claude', 'CLAUDE.md'],
            self::Opencode => ['opencode.json', 'opencode.jsonc', '.opencode'],
        };
    }
}
