<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <km@move-elevator.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Mcp;

use KonradMichalik\Typo3AiMate\Mate\Typo3CliRunner;
use Mcp\Capability\Attribute\McpTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * UpgradeWizardsTool.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
final readonly class UpgradeWizardsTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    #[McpTool(name: 'typo3-upgrade-wizards', title: 'TYPO3 Upgrade Wizards', description: 'List all TYPO3 upgrade wizards (pending and done) with identifier, title, description and status — which DB/config migrations are still outstanding. Read-only; running a wizard is not exposed.')]
    public function list(): string
    {
        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:upgrade:wizards'));
    }
}
