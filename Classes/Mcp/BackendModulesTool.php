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
 * BackendModulesTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class BackendModulesTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    #[McpTool(name: 'typo3-backend-modules', title: 'TYPO3 Backend Modules', description: 'Which backend modules this installation registers, each with its parent, route path, access level and navigation component. Takes no arguments. The navigation component is the resolved one: a submodule declaring inheritNavigationComponent takes its parent\'s, so the value here is what the module actually renders with rather than what its own Configuration/Backend/Modules.php says. No user context is applied, so this is the registry, not one editor\'s menu.', annotations: new ToolAnnotations(readOnlyHint: true))]
    public function list(): string
    {
        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:backend:modules'));
    }
}
