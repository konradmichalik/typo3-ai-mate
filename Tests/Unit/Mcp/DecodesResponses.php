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

/**
 * DecodesResponses.
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

        return $data;
    }
}
