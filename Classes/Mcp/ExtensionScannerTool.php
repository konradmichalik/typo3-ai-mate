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
use KonradMichalik\Typo3AiMate\Mcp\Enum\OutputMode;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;

/**
 * ExtensionScannerTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class ExtensionScannerTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string|null $extension extension key to scan; omit to scan all non-core extensions
     * @param OutputMode  $mode      summary (default, matches grouped by message with strong/weak counts and affected files) | full (individual matches with line content)
     * @param bool        $ownCode   true skips third-party (vendor) packages and scans only own code
     */
    #[McpTool(
        name: 'typo3-extension-scanner',
        title: 'TYPO3 Extension Scanner',
        description: 'Start here for upgrade readiness. Static scan of PHP code against the core breaking/deprecation matchers — reports where code breaks in the installed target version. Defaults to a compact summary: matches grouped by message with strong/weak counts and the affected files (plus a per-origin rollup when scanning all). Pass mode=full for individual matches with line content. Pass an extension key to scan one; omit it to scan all non-core extensions, and set ownCode=true to skip third-party (vendor) packages.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'description' => 'Shape depends on extension and mode. A single unknown/inactive extension answers with only {extension, error} rather than a scan result.',
            'properties' => [
                'extension' => ['type' => 'string', 'description' => 'Present only when scanning a single extension: echoed back key.'],
                'error' => ['type' => 'string', 'description' => 'Present only when a single requested extension is unknown, inactive, or its path is missing — the scan did not run at all.'],
                'origin' => ['type' => 'string', 'enum' => ['own', 'thirdParty'], 'description' => 'Present only when scanning a single extension.'],
                'mode' => ['type' => 'string', 'enum' => ['summary', 'full'], 'description' => 'Echoes back the requested format.'],
                'statistics' => ['type' => 'object', 'description' => 'Present only when scanning a single extension: {effectiveCodeLines, ignoredLines, filesScanned, filesSkipped, matchCount, strong, weak}. matchCount=0 is a clean scan, a real answer.'],
                'matches' => [
                    'type' => 'array',
                    'description' => 'Present only when scanning a single extension. mode=full: individual {file, line, indicator, message, lineContent}, capped with _truncated. mode=summary: grouped {message, indicator, count, files} sorted by count descending. Empty means no breaking/deprecated usages were found — a real answer, not a failure.',
                ],
                '_truncated' => ['type' => 'boolean', 'description' => 'Present only for a single extension in mode=full: true if matches was capped at 200 entries.'],
                'extensions' => ['type' => 'array', 'description' => 'Present only when scanning all extensions: one entry per extension (mode=full), shaped like this same schema per extension, or (mode=summary) only extensions with at least one match.'],
                'totals' => ['type' => 'object', 'description' => 'Present only when scanning all extensions in mode=summary: {extensionsScanned, extensionsWithMatches, ownStrong, ownWeak, thirdPartyStrong, thirdPartyWeak} rollup.'],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all (e.g. the console was unreachable) — distinct from a clean scan (matchCount=0) or a per-extension error, both of which are real answers.'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function scan(?string $extension = null, OutputMode $mode = OutputMode::Summary, bool $ownCode = false): CallToolResult
    {
        $arguments = null !== $extension && '' !== $extension ? [$extension] : [];
        $options = ['format' => $mode->value];
        if ($ownCode) {
            $options['own-code'] = true;
        }

        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:upgrade:scan', $arguments, $options));
    }
}
