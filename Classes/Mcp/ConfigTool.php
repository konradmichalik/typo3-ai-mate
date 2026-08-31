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
use KonradMichalik\Typo3AiMate\Mcp\Enum\ConfigSection;
use Symfony\AI\Mate\Attribute\MateTool;

/**
 * ConfigTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class ConfigTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string|null   $path    slash-separated path scoped to section: FE or SYS/features for confvars, a feature toggle name for features, an extension key (optionally with a sub-path) for extension; omit for a compact default (top-level keys plus feature toggles for confvars, all keys for features, extension keys with configuration for extension)
     * @param ConfigSection $section confvars (default, TYPO3_CONF_VARS) | features (SYS/features shortcut) | extension (EXTENSIONS/<key> shortcut, path is the extension key)
     */
    #[MateTool(
        name: 'typo3-config',
        title: 'TYPO3 Configuration',
        description: 'TYPO3_CONF_VARS, feature toggles, or one extension\'s configuration — settings.php/additional.php/env vars only produce the effective value at runtime, so read this instead of guessing. Secrets are masked recursively by key (password, secret, token, credential, encryptionKey, apiKey, …) and remaining string values are scanned for embedded credentials (DSNs, connection strings); masking cannot be disabled. Omit path for a compact overview; pass a path to drill in.',
    )]
    public function dump(?string $path = null, ConfigSection $section = ConfigSection::Confvars): string
    {
        $options = ['section' => $section->value];
        if (null !== $path && '' !== $path) {
            $options['path'] = $path;
        }

        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:config:dump', [], $options));
    }
}
