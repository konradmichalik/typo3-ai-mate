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
use Symfony\AI\Mate\Attribute\MateTool;

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
    #[MateTool(
        name: 'typo3-commands',
        title: 'TYPO3 Commands',
        description: 'All registered console commands (name, description, synopsis), including third-party extensions — read this instead of guessing CLI commands from other frameworks or TYPO3 versions. Optional substring filter on the command name; ownOnly=true hides core and third-party (vendor) commands.',
    )]
    public function list(?string $pattern = null, bool $ownOnly = false): string
    {
        $options = [];
        if (null !== $pattern && '' !== $pattern) {
            $options['pattern'] = $pattern;
        }
        if ($ownOnly) {
            $options['own-only'] = true;
        }

        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:commands:list', [], $options));
    }
}
