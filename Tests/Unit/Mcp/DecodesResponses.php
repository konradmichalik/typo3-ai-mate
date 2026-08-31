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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Mcp;

use PHPUnit\Framework\Assert;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

use function array_key_exists;

/**
 * DecodesResponses.
 *
 * Unwraps {@see ResponseEncoder::encodeUntrusted()}'s envelope transparently:
 * most callers below only care about the payload a tool computed, not whether
 * it went through {@see \KonradMichalik\Typo3AiMate\Mate\ToolResult::from()} or
 * {@see \KonradMichalik\Typo3AiMate\Mate\ToolResult::untrusted()}. The envelope
 * itself is asserted once, explicitly, in ToolResultTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
trait DecodesResponses
{
    /**
     * @return array<mixed>
     */
    private function decode(string $response): array
    {
        $data = ResponseEncoder::decode($response);
        Assert::assertIsArray($data);

        if (array_key_exists('untrusted_data', $data) && array_key_exists('_security_notice', $data)) {
            $data = $data['untrusted_data'];
            Assert::assertIsArray($data);
        }

        return $data;
    }
}
