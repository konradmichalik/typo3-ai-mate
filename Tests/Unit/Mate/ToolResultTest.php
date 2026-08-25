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
use Mcp\Schema\Content\TextContent;
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
    public function wrapsSuccessDataAsTextAndStructuredContent(): void
    {
        $data = ['registered' => false, 'suggestions' => ['actions-add']];

        $result = ToolResult::from($data);

        self::assertFalse($result->isError);
        self::assertSame($data, $result->structuredContent);
        self::assertCount(1, $result->content);
        $content = $result->content[0];
        self::assertInstanceOf(TextContent::class, $content);
        self::assertIsString($content->text);
        self::assertSame($data, ResponseEncoder::decode($content->text));
    }

    #[Test]
    public function convertsAConsoleFailureIntoAnUnsupportedResult(): void
    {
        $result = ToolResult::from(['error' => 'TYPO3 command "x" failed.']);

        $expected = ['unsupported' => true, 'reason' => 'TYPO3 command "x" failed.'];
        self::assertSame($expected, $result->structuredContent);
        $content = $result->content[0];
        self::assertInstanceOf(TextContent::class, $content);
        self::assertIsString($content->text);
        self::assertSame($expected, ResponseEncoder::decode($content->text));
    }

    #[Test]
    public function doesNotTreatADomainErrorFieldAlongsideOtherDataAsUnsupported(): void
    {
        $data = ['error' => 'ignored', 'other' => 'field'];

        $result = ToolResult::from($data);

        self::assertSame($data, $result->structuredContent);
    }
}
