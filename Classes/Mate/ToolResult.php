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

namespace KonradMichalik\Typo3AiMate\Mate;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

use function is_string;

/**
 * ToolResult.
 *
 * Wraps a tool's response data as a {@see CallToolResult}: the same TOON/JSON
 * text the model always saw, plus structuredContent built from the same data
 * so a tool's declared outputSchema is actually populated by the MCP SDK
 * instead of being inert metadata.
 *
 * {@see Typo3CliRunner::jsonOrError()}'s sole failure shape, {"error": "..."},
 * means the tool could not answer at all (console unreachable, bad bootstrap,
 * ...) - not a domain miss like registered=false or found=false. It is
 * reported as {"unsupported": true, "reason": "..."} instead, so a model can
 * tell "nothing there" from "could not reach it".
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class ToolResult
{
    /**
     * The outputSchema property for the standard `reason` field every
     * "unsupported" result carries. Shared by every tool's schema (repeating
     * this literal per tool invited drift between them); a tool whose
     * unsupported case needs a more specific description writes its own
     * `reason` property instead of using this constant.
     *
     * @var array{type: string, description: string}
     */
    public const REASON_PROPERTY = ['type' => 'string', 'description' => 'Present only when unsupported is true: why the tool could not answer.'];

    /**
     * @param array<mixed> $data
     */
    public static function from(array $data): CallToolResult
    {
        $payload = self::isUnreachable($data) ? ['unsupported' => true, 'reason' => $data['error']] : $data;

        return new CallToolResult([new TextContent(ResponseEncoder::encode($payload))], structuredContent: $payload);
    }

    /**
     * @param array<mixed> $data
     */
    private static function isUnreachable(array $data): bool
    {
        return ['error'] === array_keys($data) && is_string($data['error']);
    }
}
