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

use Symfony\AI\Mate\Encoding\ResponseEncoder;

use function is_string;

/**
 * ToolResult.
 *
 * Encodes a tool's response data the same way every #[MateTool] method must
 * return it: as the TOON/JSON string {@see ResponseEncoder} produces. {@see
 * untrusted()} additionally wraps the payload as data captured from the
 * inspected TYPO3 installation (records, labels, log lines, rendered markup,
 * ...), which is frequently authored by editors or third-party extensions and
 * must never be read as instructions.
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
     * @param array<mixed> $data
     */
    public static function from(array $data): string
    {
        return ResponseEncoder::encode(self::payload($data));
    }

    /**
     * @param array<mixed> $data
     */
    public static function untrusted(array $data): string
    {
        return ResponseEncoder::encodeUntrusted(self::payload($data));
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function payload(array $data): array
    {
        return self::isUnreachable($data) ? ['unsupported' => true, 'reason' => $data['error']] : $data;
    }

    /**
     * @param array<mixed> $data
     */
    private static function isUnreachable(array $data): bool
    {
        return ['error'] === array_keys($data) && is_string($data['error']);
    }
}
