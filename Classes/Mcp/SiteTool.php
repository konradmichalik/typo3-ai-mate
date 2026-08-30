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
 * SiteTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class SiteTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string|null $identifier limit the default listing to a single site by identifier; ignored when pageId is given
     * @param int|null    $pageId     switch to URL mode and resolve the frontend/backend URL for this page; omit entirely to list sites instead. Pass 0 for a URL without knowing a page id — resolves to the root page of the first configured site
     * @param int         $language   site language id used when resolving a URL (0 = default language)
     */
    #[MateTool(
        name: 'typo3-site',
        title: 'TYPO3 Site Configuration',
        description: 'Configured sites — identifier, base URL, root page id, languages (id, locale, base path, title) and error handling. Needed to construct sensible arguments for typo3-render-page and typo3-typoscript, and to diagnose multi-language setups. Omit pageId to list all sites (or one via identifier); pass pageId to instead resolve its absolute frontend URL (via the site router, same resolution typo3-render-page uses) plus the matching backend URL — a lookup with no rendering side effect. pageId=0 resolves the root page of the first configured site when no specific page id is known.',
    )]
    public function dump(?string $identifier = null, ?int $pageId = null, int $language = 0): string
    {
        $options = [];
        if (null !== $pageId) {
            $options['pageId'] = $pageId;
            $options['language'] = $language;
        } elseif (null !== $identifier && '' !== $identifier) {
            $options['identifier'] = $identifier;
        }

        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:site:dump', [], $options));
    }
}
