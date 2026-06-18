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
 * ExtensionScannerTool.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
final readonly class ExtensionScannerTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    #[McpTool(name: 'typo3-extension-scanner', title: 'TYPO3 Extension Scanner', description: 'Static scan of PHP code against the core breaking/deprecation matchers — reports where code breaks in the installed target version. Defaults to a compact summary: matches grouped by message with strong/weak counts and the affected files (plus a per-origin rollup when scanning all). Pass mode=full for individual matches with line content. Pass an extension key to scan one; omit it to scan all non-core extensions, and set ownCode=true to skip third-party (vendor) packages.')]
    public function scan(?string $extension = null, string $mode = 'summary', bool $ownCode = false): string
    {
        $arguments = null !== $extension && '' !== $extension ? [$extension] : [];
        $options = ['format' => $mode];
        if ($ownCode) {
            $options['own-code'] = true;
        }

        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:upgrade:scan', $arguments, $options));
    }
}
