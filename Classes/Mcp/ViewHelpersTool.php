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
 * ViewHelpersTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class ViewHelpersTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string|null $names     Comma-separated ViewHelper names with their prefix to check in one call, e.g. f:link.page,f:format.date. A miss carries the closest names of the same namespace as suggestions, an unknown prefix the closest global prefixes
     * @param string|null $namespace global prefix whose ViewHelper names to list, e.g. f; used when names is omitted
     */
    #[MateTool(
        name: 'typo3-viewhelpers',
        title: 'TYPO3 ViewHelpers',
        description: 'Whether a Fluid ViewHelper such as f:link.page exists in this installation, which class it resolves to, and its registered arguments with type, required flag, default and description. exists=false is the answer, not an empty result: an unresolvable ViewHelper makes the whole template fail to parse. Pass several names at once; a miss carries the closest ViewHelper names of the same namespace as suggestions, an unknown prefix the closest global prefixes. acceptsUndeclaredArguments=true marks a tag-based ViewHelper that renders unlisted arguments as tag attributes. With namespace instead of names you get the ViewHelper names of that prefix only. Resolved through the runtime ViewHelperResolver, so global namespaces of every package and ModifyNamespacesEvent listeners apply. Do not grep vendor for ViewHelper classes.',
    )]
    public function lookup(?string $names = null, ?string $namespace = null): string
    {
        $options = array_filter(
            ['names' => $names, 'namespace' => $namespace],
            static fn (?string $value): bool => null !== $value && '' !== $value,
        );

        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:fluid:viewhelpers', [], $options));
    }
}
