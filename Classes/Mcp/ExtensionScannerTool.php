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
    #[McpTool(name: 'typo3-extension-scanner', description: 'Static scan of PHP code against the core breaking/deprecation matchers — reports where code breaks in the installed target version (message, line, strong/weak indicator). Pass an extension key to scan one; omit it to scan all non-core extensions (own + third-party).')]
    public function scan(?string $extension = null): array
    {
        $arguments = null !== $extension && '' !== $extension ? [$extension] : [];

        return $this->typo3->jsonOrError('typo3-ai-mate:upgrade:scan', $arguments);
    }
}
