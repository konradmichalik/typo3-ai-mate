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
use KonradMichalik\Typo3AiMate\Mcp\Enum\TsConfigType;
use Mcp\Capability\Attribute\McpTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * TsConfigTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TsConfigTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param int          $pageId page UID whose rootline-merged Page TSconfig should be dumped
     * @param TsConfigType $type   page (default; mod.*, TCEFORM, TCEMAIN, RTE) | user (per backend user)
     * @param int|null     $user   BE user UID — required when type=user
     * @param string|null  $path   Dotted scope to limit large output to one branch, e.g. mod.web_layout; omit for the whole tree.
     */
    #[McpTool(name: 'typo3-tsconfig', title: 'TYPO3 Page/User TSconfig', description: 'Resolved Page TSconfig (rootline-merged: mod.*, TCEFORM, TCEMAIN, RTE) or User TSconfig — the backend configuration layer that no single file reveals. Distinct from frontend TypoScript (typo3-typoscript). Scope large output with a dotted path, e.g. mod.web_layout.BackendLayouts.')]
    public function dump(int $pageId, TsConfigType $type = TsConfigType::Page, ?int $user = null, ?string $path = null): string
    {
        $options = ['type' => $type->value];
        if (null !== $user) {
            $options['user'] = $user;
        }
        if (null !== $path && '' !== $path) {
            $options['path'] = $path;
        }

        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:tsconfig:dump', [$pageId], $options));
    }
}
