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
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * IconsTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class IconsTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string|null $identifiers Comma-separated icon identifiers to check in one call, e.g. actions-add,tx-myext-plugin. A miss carries the closest registered identifiers as suggestions.
     */
    #[McpTool(name: 'typo3-icons', title: 'TYPO3 Icons', description: 'Whether an icon identifier is registered in this installation, and which extension provides it. registered=false is the answer, not an empty result: an unregistered identifier renders no icon at all, not a placeholder. Pass several identifiers at once; a miss carries the closest registered identifiers as suggestions, so a half-remembered name is answered rather than denied. Without arguments you get the identifier count grouped by leading segment. The registry is the resolved one, covering core T3Icons, every extension\'s Configuration/Icons.php and runtime registrations — do not grep vendor/typo3 for this.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function lookup(?string $identifiers = null): string
    {
        $options = null !== $identifiers && '' !== $identifiers ? ['identifiers' => $identifiers] : [];

        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:icons:lookup', [], $options));
    }
}
