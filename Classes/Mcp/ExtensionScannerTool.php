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

/**
 * ExtensionScannerTool.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
final readonly class ExtensionScannerTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @return array<mixed>
     */
    #[McpTool(name: 'typo3-extension-scanner', description: 'Static scan of an extension\'s PHP code against the core breaking/deprecation matchers — reports where your own code breaks in the installed target version (message, line, strong/weak indicator).')]
    public function scan(string $extension): array
    {
        return $this->typo3->json('typo3-ai-mate:upgrade:scan', [$extension]);
    }
}
