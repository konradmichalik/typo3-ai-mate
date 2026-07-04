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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command\Support;

use KonradMichalik\Typo3AiMate\Command\Support\LogAggregator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * LogAggregatorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class LogAggregatorTest extends TestCase
{
    #[Test]
    public function summaryDeduplicatesCountsAndReportsTotals(): void
    {
        $payload = (new LogAggregator(2000))->summary(self::entries(), 50);

        self::assertSame('summary', $payload['mode']);
        self::assertSame(3, $payload['totalMatched']);
        self::assertSame(2, $payload['distinct']);
        self::assertSame('Boom', $payload['entries'][0]['message']);
        self::assertSame(2, $payload['entries'][0]['count']);
        self::assertSame('T3', $payload['entries'][0]['lastSeen']);
        self::assertSame('r1', $payload['entries'][0]['exampleRequestId']);
    }

    #[Test]
    public function summaryCapsTheNumberOfDistinctMessages(): void
    {
        $payload = (new LogAggregator(2000))->summary(self::entries(), 1);

        self::assertSame(2, $payload['distinct']);
        self::assertCount(1, $payload['entries']);
    }

    #[Test]
    public function recentKeepsOnlyTheMostRecentEntriesWithinTheWindow(): void
    {
        $entries = [
            ['message' => 'One', 'level' => 'INFO', 'component' => 'c', 'time' => 'T1', 'request_id' => 'a'],
            ['message' => 'Two', 'level' => 'INFO', 'component' => 'c', 'time' => 'T2', 'request_id' => 'b'],
            ['message' => 'Three', 'level' => 'INFO', 'component' => 'c', 'time' => 'T3', 'request_id' => 'c'],
        ];

        $payload = (new LogAggregator(2000))->recent($entries, 2, 2000);

        self::assertSame('full', $payload['mode']);
        self::assertSame(3, $payload['totalMatched']);
        self::assertSame(['Two', 'Three'], array_column($payload['entries'], 'message'));
    }

    #[Test]
    public function recentTruncatesLongTraces(): void
    {
        $entries = [
            ['message' => 'Boom', 'level' => 'ERROR', 'component' => 'c', 'time' => 'T1', 'request_id' => 'a', 'trace' => str_repeat('x', 500)],
        ];

        $payload = (new LogAggregator(2000))->recent($entries, 10, 50);

        $trace = $payload['entries'][0]['trace'];
        self::assertIsString($trace);
        self::assertStringEndsWith('…[truncated]', $trace);
        self::assertLessThan(500, mb_strlen($trace));
    }

    #[Test]
    public function summaryAcceptsAGeneratorSource(): void
    {
        $generator = (static function (): iterable {
            yield ['message' => 'Boom', 'level' => 'ERROR', 'component' => 'c', 'time' => 'T1', 'request_id' => 'a'];
            yield ['message' => 'Boom', 'level' => 'ERROR', 'component' => 'c', 'time' => 'T2', 'request_id' => 'b'];
        })();

        $payload = (new LogAggregator(2000))->summary($generator, 50);

        self::assertSame(2, $payload['totalMatched']);
        self::assertSame(1, $payload['distinct']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function entries(): array
    {
        return [
            ['message' => 'Boom', 'level' => 'ERROR', 'component' => 'TYPO3.CMS.Core', 'time' => 'T1', 'request_id' => 'r1'],
            ['message' => 'Other', 'level' => 'WARNING', 'component' => 'TYPO3.CMS.Foo', 'time' => 'T2', 'request_id' => 'r2'],
            ['message' => 'Boom', 'level' => 'ERROR', 'component' => 'TYPO3.CMS.Core', 'time' => 'T3', 'request_id' => 'r3'],
        ];
    }
}
