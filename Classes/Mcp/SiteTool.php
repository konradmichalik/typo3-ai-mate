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
    #[McpTool(
        name: 'typo3-site',
        title: 'TYPO3 Site Configuration',
        description: 'Configured sites — identifier, base URL, root page id, languages (id, locale, base path, title) and error handling. Needed to construct sensible arguments for typo3-render-page and typo3-typoscript, and to diagnose multi-language setups. Omit pageId to list all sites (or one via identifier); pass pageId to instead resolve its absolute frontend URL (via the site router, same resolution typo3-render-page uses) plus the matching backend URL — a lookup with no rendering side effect. pageId=0 resolves the root page of the first configured site when no specific page id is known.',
        annotations: new ToolAnnotations(readOnlyHint: true),
        outputSchema: [
            'type' => 'object',
            'description' => 'Shape depends on the request: {sites} (neither pageId nor identifier given), {site} (identifier given), or the URL fields (pageId given).',
            'properties' => [
                'sites' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Present only when neither pageId nor identifier was given: every configured site, each {identifier, base, rootPageId, languages, errorHandling}.'],
                'site' => [
                    'type' => 'object',
                    'description' => 'Present only when identifier was given.',
                    'properties' => [
                        'identifier' => ['type' => 'string'],
                        'base' => ['type' => 'string'],
                        'rootPageId' => ['type' => 'integer'],
                        'languages' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'locale' => ['type' => 'string'], 'base' => ['type' => 'string'], 'title' => ['type' => 'string']]]],
                        'errorHandling' => ['type' => 'array', 'items' => ['type' => 'object']],
                    ],
                ],
                'pageId' => ['type' => 'integer', 'description' => 'Present only when pageId was given: echoed back (resolved to the first configured site\'s root page when 0 was passed).'],
                'languageId' => ['type' => 'integer', 'description' => 'Present only when pageId was given: echoed back site language id.'],
                'frontendUrl' => ['type' => 'string', 'description' => 'Present only when pageId was given: the resolved absolute frontend URL.'],
                'backendUrl' => ['type' => ['string', 'null'], 'description' => 'Present only when pageId was given: the matching backend edit URL, or null when it could not be built.'],
                'unsupported' => ['type' => 'boolean', 'description' => 'true if the tool could not answer at all: an unknown site identifier, no site configured, an unresolvable page URL, or the console was unreachable.'],
                'reason' => ToolResult::REASON_PROPERTY,
            ],
        ],
    )]
    public function dump(?string $identifier = null, ?int $pageId = null, int $language = 0): CallToolResult
    {
        $options = [];
        if (null !== $pageId) {
            $options['pageId'] = $pageId;
            $options['language'] = $language;
        } elseif (null !== $identifier && '' !== $identifier) {
            $options['identifier'] = $identifier;
        }

        return ToolResult::from($this->typo3->jsonOrError('typo3-ai-mate:site:dump', [], $options));
    }
}
