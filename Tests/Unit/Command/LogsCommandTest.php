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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command;

use KonradMichalik\Typo3AiMate\Command\LogsCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * LogsCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class LogsCommandTest extends TestCase
{
    use WithTemporaryVarPath;

    private LogsCommand $command;

    protected function setUp(): void
    {
        $this->command = new LogsCommand();
        $this->initVarPath();
    }

    protected function tearDown(): void
    {
        $this->cleanupVarPath();
    }

    #[Test]
    public function parseHeaderLineParsesTheRfc2822TimestampWithSpaces(): void
    {
        $line = 'Mon, 15 Jun 2026 16:16:25 +0200 [CRITICAL] request="8fb82b2090caa" component="TYPO3.CMS.Core.Error.DebugExceptionHandler": Core: Exception handler: boom';

        $entry = $this->command->parseHeaderLine($line);

        self::assertNotNull($entry);
        self::assertSame('Mon, 15 Jun 2026 16:16:25 +0200', $entry['time']);
        self::assertSame('CRITICAL', $entry['level']);
        self::assertSame('TYPO3.CMS.Core.Error.DebugExceptionHandler', $entry['component']);
        self::assertSame('8fb82b2090caa', $entry['request_id']);
        self::assertSame('Core: Exception handler: boom', $entry['message']);
    }

    #[Test]
    public function entryReachesSinceAlwaysMatchesWithoutALowerBound(): void
    {
        self::assertTrue($this->command->entryReachesSince(['time' => '2020-01-01 00:00:00'], null));
    }

    #[Test]
    public function entryReachesSinceComparesTheTimestampAgainstTheBound(): void
    {
        $bound = (int) strtotime('2024-01-01 00:00:00');

        self::assertTrue($this->command->entryReachesSince(['time' => '2024-06-01 12:00:00'], $bound));
        self::assertFalse($this->command->entryReachesSince(['time' => '2023-01-01 00:00:00'], $bound));
        self::assertFalse($this->command->entryReachesSince(['time' => 'not-a-date'], $bound));
    }

    #[Test]
    public function parseHeaderLineReturnsNullForNonHeaderLines(): void
    {
        self::assertNull($this->command->parseHeaderLine('#0 /some/stack/trace/line.php(42): Foo->bar()'));
        self::assertNull($this->command->parseHeaderLine(''));
    }

    #[Test]
    public function parseFileGroupsContinuationLinesIntoTheCurrentEntryTrace(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'typo3-ai-mate-log-');
        file_put_contents($file, implode("\n", [
            'Mon, 15 Jun 2026 16:16:25 +0200 [CRITICAL] request="abc" component="TYPO3.CMS.Core": Boom',
            '#0 /trace/line/one.php',
            '#1 /trace/line/two.php',
            'Mon, 15 Jun 2026 16:16:26 +0200 [INFO] request="def" component="TYPO3.CMS.Foo": Hello',
            '',
        ]));

        $entries = $this->command->parseFile($file);
        @unlink($file);

        self::assertCount(2, $entries);
        self::assertSame('CRITICAL', $entries[0]['level']);
        $trace = $entries[0]['trace'] ?? null;
        self::assertIsString($trace);
        self::assertStringContainsString('#0 /trace/line/one.php', $trace);
        self::assertStringContainsString('#1 /trace/line/two.php', $trace);
        self::assertSame('Hello', $entries[1]['message']);
        self::assertArrayNotHasKey('trace', $entries[1]);
    }

    #[Test]
    public function parseFileReturnsEmptyForUnreadableFile(): void
    {
        self::assertSame([], $this->command->parseFile('/does/not/exist.log'));
    }

    #[Test]
    public function resolveMinSeverityMapsLevelNames(): void
    {
        self::assertSame(3, $this->command->resolveMinSeverity('error'));
        self::assertSame(0, $this->command->resolveMinSeverity('EMERGENCY'));
        self::assertNull($this->command->resolveMinSeverity(null));
        self::assertNull($this->command->resolveMinSeverity(''));
        self::assertNull($this->command->resolveMinSeverity('bogus'));
    }

    #[Test]
    public function entryMatchesAppliesSeverityComponentRequestAndQueryFilters(): void
    {
        $entry = [
            'level' => 'ERROR',
            'component' => 'TYPO3.CMS.Core.Error',
            'request_id' => 'abc123',
            'message' => 'Something failed badly',
        ];

        // No filters -> matches.
        self::assertTrue($this->command->entryMatches($entry, null, null, null, null));

        // Min severity ERROR (3): an ERROR (3) passes, but a stricter-than threshold of CRITICAL (2) rejects it.
        self::assertTrue($this->command->entryMatches($entry, 3, null, null, null));
        self::assertFalse($this->command->entryMatches($entry, 2, null, null, null));

        // Component substring.
        self::assertTrue($this->command->entryMatches($entry, null, 'Core', null, null));
        self::assertFalse($this->command->entryMatches($entry, null, 'Frontend', null, null));

        // Request id is matched exactly.
        self::assertTrue($this->command->entryMatches($entry, null, null, null, 'abc123'));
        self::assertFalse($this->command->entryMatches($entry, null, null, null, 'other'));

        // Query is a message substring.
        self::assertTrue($this->command->entryMatches($entry, null, null, 'failed', null));
        self::assertFalse($this->command->entryMatches($entry, null, null, 'success', null));
    }

    #[Test]
    public function allEntriesParsesEveryLogFileInVarLog(): void
    {
        $this->writeLog('test', [
            'Mon, 15 Jun 2026 16:16:25 +0200 [ERROR] request="a" component="TYPO3.CMS.Core": One',
        ]);
        $this->writeLog('deprecations', [
            'Mon, 15 Jun 2026 16:16:26 +0200 [NOTICE] request="b" component="TYPO3.CMS.deprecations": Two',
        ]);

        $messages = array_column($this->command->allEntries(), 'message');

        self::assertContains('One', $messages);
        self::assertContains('Two', $messages);
    }

    #[Test]
    public function resolveSinceParsesRelativeOffsetsAndDates(): void
    {
        $now = time();
        $thirtyMinutes = $this->command->resolveSince('30m');
        self::assertIsInt($thirtyMinutes);
        self::assertEqualsWithDelta($now - 1800, $thirtyMinutes, 5);

        $twoDays = $this->command->resolveSince('2d');
        self::assertIsInt($twoDays);
        self::assertEqualsWithDelta($now - 172800, $twoDays, 5);

        self::assertSame(strtotime('Mon, 15 Jun 2026 16:16:25 +0200'), $this->command->resolveSince('Mon, 15 Jun 2026 16:16:25 +0200'));
        self::assertNull($this->command->resolveSince(null));
        self::assertNull($this->command->resolveSince(''));
    }

    #[Test]
    public function aggregateDeduplicatesByMessageCountsAndDropsTrace(): void
    {
        $summaries = $this->command->aggregate([
            ['message' => 'Boom', 'level' => 'ERROR', 'component' => 'TYPO3.CMS.Core', 'time' => 'T1', 'request_id' => 'r1', 'trace' => '#0 a'],
            ['message' => 'Other', 'level' => 'WARNING', 'component' => 'TYPO3.CMS.Foo', 'time' => 'T2', 'request_id' => 'r2'],
            ['message' => 'Boom', 'level' => 'ERROR', 'component' => 'TYPO3.CMS.Core', 'time' => 'T3', 'request_id' => 'r3'],
        ]);

        self::assertSame('Boom', $summaries[0]['message']);
        self::assertSame(2, $summaries[0]['count']);
        self::assertSame('T3', $summaries[0]['lastSeen']);
        self::assertSame('r1', $summaries[0]['exampleRequestId']);
        self::assertSame('ERROR', $summaries[0]['level']);
        self::assertArrayNotHasKey('trace', $summaries[0]);
    }

    #[Test]
    public function aggregateCapsLongMessageBodiesAndMergesNearIdenticalOnes(): void
    {
        $long = 'Exception: '.str_repeat('x', 5000);
        $summaries = $this->command->aggregate([
            ['message' => $long.' AAA', 'level' => 'ERROR', 'component' => 'TYPO3.CMS.Core', 'time' => 'T1', 'request_id' => 'r1'],
            ['message' => $long.' BBB', 'level' => 'ERROR', 'component' => 'TYPO3.CMS.Core', 'time' => 'T2', 'request_id' => 'r2'],
        ]);

        // Both share the same 2000-char prefix once capped → one merged entry.
        self::assertCount(1, $summaries);
        self::assertSame(2, $summaries[0]['count']);
        self::assertLessThan(mb_strlen($long), mb_strlen($summaries[0]['message']));
        self::assertStringEndsWith('…[truncated]', $summaries[0]['message']);
    }

    #[Test]
    public function executeReturnsSummaryByDefault(): void
    {
        $this->writeLog('test', [
            'Mon, 15 Jun 2026 16:16:25 +0200 [ERROR] request="abc" component="TYPO3.CMS.Core": First failure',
            'Mon, 15 Jun 2026 16:16:26 +0200 [ERROR] request="def" component="TYPO3.CMS.Core": First failure',
            'Mon, 15 Jun 2026 16:16:27 +0200 [INFO] request="ghi" component="TYPO3.CMS.Foo": Just info',
        ]);

        $tester = new CommandTester($this->command);
        $exitCode = $tester->execute(['--level' => 'error']);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('summary', $result['mode']);
        self::assertSame(2, $result['totalMatched']);
        self::assertSame(1, $result['distinct']);
        $entries = $result['entries'];
        self::assertIsArray($entries);
        self::assertCount(1, $entries);
        $first = $entries[0];
        self::assertIsArray($first);
        self::assertSame('First failure', $first['message']);
        self::assertSame(2, $first['count']);
    }

    #[Test]
    public function executeFullFormatAppliesTheMostRecentLimit(): void
    {
        $this->writeLog('test', [
            'Mon, 15 Jun 2026 16:16:25 +0200 [INFO] request="a" component="TYPO3.CMS.Core": One',
            'Mon, 15 Jun 2026 16:16:26 +0200 [INFO] request="b" component="TYPO3.CMS.Core": Two',
            'Mon, 15 Jun 2026 16:16:27 +0200 [INFO] request="c" component="TYPO3.CMS.Core": Three',
        ]);

        $tester = new CommandTester($this->command);
        $tester->execute(['--format' => 'full', '--limit' => '1']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('full', $result['mode']);
        self::assertSame(3, $result['totalMatched']);
        $entries = $result['entries'];
        self::assertIsArray($entries);
        self::assertCount(1, $entries);
        $first = $entries[0];
        self::assertIsArray($first);
        self::assertSame('Three', $first['message']);
    }

    #[Test]
    public function executeFullFormatTruncatesLongTraces(): void
    {
        $longTrace = str_repeat('#0 /very/long/stack/frame.php ', 200);
        $this->writeLog('test', array_merge(
            ['Mon, 15 Jun 2026 16:16:25 +0200 [ERROR] request="abc" component="TYPO3.CMS.Core": Boom'],
            [$longTrace],
        ));

        $tester = new CommandTester($this->command);
        $tester->execute(['--format' => 'full', '--trace-limit' => '50']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        $entries = $result['entries'];
        self::assertIsArray($entries);
        $first = $entries[0];
        self::assertIsArray($first);
        $trace = $first['trace'];
        self::assertIsString($trace);
        self::assertStringEndsWith('…[truncated]', $trace);
        self::assertLessThan(mb_strlen($longTrace), mb_strlen($trace));
    }
}
