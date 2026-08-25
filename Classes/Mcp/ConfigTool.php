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
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

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
    #[McpTool(
        name: 'typo3-config',
        title: 'TYPO3 Configuration',
        description: 'TYPO3_CONF_VARS, feature toggles, or one extension\'s configuration — settings.php/additional.php/env vars only produce the effective value at runtime, so read this instead of guessing. Secrets are masked recursively by key (password, secret, token, credential, encryptionKey, apiKey, …) and remaining string values are scanned for embedded credentials (DSNs, connection strings); masking cannot be disabled. Omit path for a compact overview; pass a path to drill in.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'description' => 'Shape depends on section and whether path was given. Without path: an overview (keys/features/extensions below). With path: {path, value}. An unresolvable path is reported as unsupported, not as an empty value.',
            'properties' => [
                'keys' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Present only for section=confvars without path: top-level TYPO3_CONF_VARS keys.'],
                'features' => ['type' => 'object', 'description' => 'Present for section=confvars without path (alongside keys), and for section=features without path: feature toggle name => value, secrets masked.'],
                'extensions' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Present only for section=extension without path: configured extension keys.'],
                'path' => ['type' => 'string', 'description' => 'Present only when path was given and resolved: the path echoed back.'],
                'value' => ['description' => 'Present only when path was given and resolved: the value at that path (any JSON type), secrets masked.'],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all: path does not resolve to anything in the selected section, or the console was unreachable.'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function dump(?string $path = null, ConfigSection $section = ConfigSection::Confvars): CallToolResult
    {
        $options = ['section' => $section->value];
        if (null !== $path && '' !== $path) {
            $options['path'] = $path;
        }

        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:config:dump', [], $options));
    }
}
