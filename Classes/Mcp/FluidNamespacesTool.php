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
 * FluidNamespacesTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class FluidNamespacesTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    #[MateTool(
        name: 'typo3-fluid-namespaces',
        title: 'TYPO3 Fluid Namespaces',
        description: 'Which Fluid ViewHelper prefixes a template may use without declaring them, mapped to the PHP namespaces they resolve to in order. Takes no arguments. Every other namespace has to be declared per template with an xmlns attribute, so this answers "is <foo:bar> available here" in one call. Resolved from the ViewHelperResolver, which on v14 has already merged Configuration/Fluid/Namespaces.php of every package with the deprecated TYPO3_CONF_VARS registration and applied any ModifyNamespacesEvent listener — none of which is visible in a single configuration file.',
    )]
    public function list(): string
    {
        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:fluid:namespaces'));
    }
}
