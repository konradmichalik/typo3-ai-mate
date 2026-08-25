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

namespace KonradMichalik\Typo3AiMate\Mcp;

use KonradMichalik\Typo3AiMate\Mate\{ToolResult, Typo3CliRunner};
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * CommandsTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class CommandsTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string|null $pattern substring matched against the command name to filter the registry; omit to list all commands
     * @param bool        $ownOnly true hides core and third-party (vendor) commands, keeping only commands from own extensions
     */
    #[McpTool(
        name: 'typo3-commands',
        title: 'TYPO3 Commands',
        description: 'All registered console commands (name, description, synopsis), including third-party extensions — read this instead of guessing CLI commands from other frameworks or TYPO3 versions. Optional substring filter on the command name; ownOnly=true hides core and third-party (vendor) commands.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'commands' => [
                    'type' => 'array',
                    'description' => 'Empty when pattern matched nothing — a real answer, not a failure.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string', 'description' => 'Absent when available is false.'],
                            'synopsis' => ['type' => 'string', 'description' => 'Absent when available is false.'],
                            'available' => ['type' => 'boolean', 'description' => 'Present (and false) only when this command\'s own constructor threw while being resolved — it is registered but currently broken independently of this listing.'],
                            'error' => ['type' => 'string', 'description' => 'Present only when available is false: the exception message from resolving this one command.'],
                        ],
                    ],
                ],
                'commandCount' => ['type' => 'integer', 'description' => 'Number of entries in commands after filtering.'],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all (e.g. the console was unreachable) — distinct from an empty commands list, which is a real answer.'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function list(?string $pattern = null, bool $ownOnly = false): CallToolResult
    {
        $options = [];
        if (null !== $pattern && '' !== $pattern) {
            $options['pattern'] = $pattern;
        }
        if ($ownOnly) {
            $options['own-only'] = true;
        }

        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:commands:list', [], $options));
    }
}
