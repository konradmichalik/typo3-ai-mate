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

use KonradMichalik\Typo3AiMate\Mate\Typo3CliRunner;
use KonradMichalik\Typo3AiMate\Mcp\Enum\ChangelogType;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * ChangelogSearchTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class ChangelogSearchTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string             $query   search terms, e.g. a class name, method name or hook name; every word must match, in the filename or the content
     * @param ChangelogType|null $type    Breaking | Deprecation | Feature | Important; omit to search all types
     * @param string|null        $version version directory prefix, e.g. "13" or "13.4"; omit to default to the installed TYPO3 major (the core ships every historical version, so this keeps results relevant)
     * @param int                $limit   maximum results (capped at 30)
     */
    #[McpTool(name: 'typo3-changelog-search', title: 'TYPO3 Changelog Search', description: 'Search the installed typo3/cms-core changelog (Breaking/Deprecation/Feature/Important RST files under Documentation/Changelog/) for migration guidance — offline, no training-data guessing. Pair with typo3-extension-scanner/typo3-deprecations: they find that an API breaks, this tool supplies how to migrate it. Defaults to the installed major version so results stay relevant; the core ships every historical major, so an unscoped search would return irrelevant hits. Each result has type, issue number, version, title, a bounded excerpt around the first match, and the relative path to read the full file.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function search(string $query, ?ChangelogType $type = null, ?string $version = null, int $limit = 10): string
    {
        $options = ['limit' => $limit];
        if (null !== $type) {
            $options['type'] = $type->value;
        }
        if (null !== $version && '' !== $version) {
            // The names differ on purpose: the console reserves `version`, while
            // the tool parameter stays as it is so the contract towards the
            // assistant does not change. Renaming either side breaks one of the two.
            $options['core-version'] = $version;
        }

        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:changelog:search', [$query], $options));
    }
}
