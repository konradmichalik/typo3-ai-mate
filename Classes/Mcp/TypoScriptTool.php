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
use KonradMichalik\Typo3AiMate\Mcp\Enum\TypoScriptType;
use Mcp\Capability\Attribute\McpTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * TypoScriptTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TypoScriptTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param int            $pageId page UID whose resolved frontend TypoScript should be dumped
     * @param TypoScriptType $type   setup (default, the object/setup tree) | constants (the constants tree)
     * @param string|null    $path   Dotted scope to limit large output to one branch, e.g. lib.foo; omit for the whole tree.
     */
    #[McpTool(name: 'typo3-typoscript', title: 'TYPO3 TypoScript', description: 'Resolved frontend TypoScript (setup|constants) of a page. Scope large output with a dotted path, e.g. lib.foo.')]
    public function dump(int $pageId, TypoScriptType $type = TypoScriptType::Setup, ?string $path = null): string
    {
        $options = ['type' => $type->value];
        if (null !== $path && '' !== $path) {
            $options['path'] = $path;
        }

        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:typoscript:dump', [$pageId], $options));
    }
}
