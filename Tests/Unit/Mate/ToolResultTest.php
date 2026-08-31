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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Mate;

use KonradMichalik\Typo3AiMate\Mate\ToolResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * ToolResultTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ToolResultTest extends TestCase
{
    #[Test]
    public function fromEncodesSuccessDataAsIs(): void
    {
        $data = ['registered' => false, 'suggestions' => ['actions-add']];

        $result = ToolResult::from($data);

        self::assertSame($data, ResponseEncoder::decode($result));
    }

    #[Test]
    public function fromConvertsAConsoleFailureIntoAnUnsupportedResult(): void
    {
        $result = ToolResult::from(['error' => 'TYPO3 command "x" failed.']);

        self::assertSame(['unsupported' => true, 'reason' => 'TYPO3 command "x" failed.'], ResponseEncoder::decode($result));
    }

    #[Test]
    public function fromDoesNotTreatADomainErrorFieldAlongsideOtherDataAsUnsupported(): void
    {
        $data = ['error' => 'ignored', 'other' => 'field'];

        self::assertSame($data, ResponseEncoder::decode(ToolResult::from($data)));
    }

    #[Test]
    public function untrustedWrapsSuccessDataUnderTheUntrustedDataEnvelope(): void
    {
        $data = ['entries' => ['a log line']];

        $decoded = ResponseEncoder::decode(ToolResult::untrusted($data));

        self::assertIsArray($decoded);
        self::assertSame(ResponseEncoder::UNTRUSTED_NOTICE, $decoded['_security_notice'] ?? null);
        self::assertSame($data, $decoded['untrusted_data'] ?? null);
    }

    #[Test]
    public function untrustedStillConvertsAConsoleFailureIntoAnUnsupportedResult(): void
    {
        $decoded = ResponseEncoder::decode(ToolResult::untrusted(['error' => 'TYPO3 command "x" failed.']));

        self::assertIsArray($decoded);
        self::assertSame(['unsupported' => true, 'reason' => 'TYPO3 command "x" failed.'], $decoded['untrusted_data'] ?? null);
    }
}
