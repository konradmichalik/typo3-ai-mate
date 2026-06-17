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
 * EventsTool.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
final readonly class EventsTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    #[McpTool(name: 'typo3-events', title: 'TYPO3 Event Listeners', description: 'Resolved PSR-14 event listener registry (which listeners fire for which event), optionally filtered by event class substring.')]
    public function list(?string $event = null): string
    {
        $options = null !== $event && '' !== $event ? ['event' => $event] : [];

        return ResponseEncoder::encode($this->typo3->jsonOrError('typo3-ai-mate:events:list', [], $options));
    }
}
