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
 * DeprecationsTool.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
final readonly class DeprecationsTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    #[McpTool(name: 'typo3-deprecations', description: 'Runtime deprecation notices, deduplicated and grouped by message with occurrence counts. Reports loggingEnabled=false when the (default-disabled) deprecations log channel is off, so an empty list is not misread as "no deprecations".')]
    public function list(): string
    {
        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:upgrade:deprecations'));
    }
}
