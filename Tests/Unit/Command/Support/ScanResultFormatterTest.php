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

use KonradMichalik\Typo3AiMate\Command\Support\ScanResultFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ScanResultFormatterTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ScanResultFormatterTest extends TestCase
{
    private ScanResultFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ScanResultFormatter();
    }

    #[Test]
    public function summaryGroupsMatchesByMessageAndCollectsDistinctFiles(): void
    {
        $result = $this->formatter->summary([
            'extension' => 'my_ext',
            'origin' => 'own',
            'matches' => [
                ['file' => 'A.php', 'line' => 10, 'indicator' => 'strong', 'message' => 'X removed', 'lineContent' => 'foo()'],
                ['file' => 'B.php', 'line' => 20, 'indicator' => 'strong', 'message' => 'X removed', 'lineContent' => 'bar()'],
                ['file' => 'A.php', 'line' => 30, 'indicator' => 'weak', 'message' => 'Y deprecated', 'lineContent' => 'baz()'],
            ],
        ]);

        self::assertSame('summary', $result['mode']);
        self::assertSame('my_ext', $result['extension']);
        $matches = $result['matches'];
        self::assertIsArray($matches);
        self::assertCount(2, $matches);

        // Most frequent group first.
        $first = $matches[0];
        self::assertIsArray($first);
        self::assertSame('X removed', $first['message']);
        self::assertSame(2, $first['count']);
        self::assertSame(['A.php', 'B.php'], $first['files']);
        // No per-occurrence line content in the summary.
        self::assertArrayNotHasKey('lineContent', $first);
    }

    #[Test]
    public function fullTruncatesMatchesBeyondTheCapAndFlagsIt(): void
    {
        $matches = [];
        for ($i = 0; $i < 250; ++$i) {
            $matches[] = ['file' => 'A.php', 'line' => $i, 'indicator' => 'strong', 'message' => 'm', 'lineContent' => 'c'];
        }

        $result = $this->formatter->full(['extension' => 'my_ext', 'matches' => $matches]);

        self::assertSame('full', $result['mode']);
        self::assertTrue($result['_truncated']);
        $truncatedMatches = $result['matches'];
        self::assertIsArray($truncatedMatches);
        self::assertCount(200, $truncatedMatches);
    }

    #[Test]
    public function fullKeepsAllMatchesWhenUnderTheCap(): void
    {
        $result = $this->formatter->full(['extension' => 'my_ext', 'matches' => [
            ['file' => 'A.php', 'line' => 1, 'indicator' => 'strong', 'message' => 'm', 'lineContent' => 'c'],
        ]]);

        self::assertFalse($result['_truncated']);
        $keptMatches = $result['matches'];
        self::assertIsArray($keptMatches);
        self::assertCount(1, $keptMatches);
    }

    #[Test]
    public function hasMatchesReadsTheMatchCountStatistic(): void
    {
        self::assertTrue($this->formatter->hasMatches(['statistics' => ['matchCount' => 3]]));
        self::assertFalse($this->formatter->hasMatches(['statistics' => ['matchCount' => 0]]));
        self::assertFalse($this->formatter->hasMatches([]));
    }

    #[Test]
    public function rollupSplitsStrongAndWeakTotalsByOrigin(): void
    {
        $totals = $this->formatter->rollup([
            ['origin' => 'own', 'statistics' => ['strong' => 2, 'weak' => 1]],
            ['origin' => 'thirdParty', 'statistics' => ['strong' => 5, 'weak' => 0]],
            ['origin' => 'own', 'statistics' => ['strong' => 0, 'weak' => 0]],
        ]);

        self::assertSame(3, $totals['extensionsScanned']);
        self::assertSame(2, $totals['extensionsWithMatches']);
        self::assertSame(2, $totals['ownStrong']);
        self::assertSame(1, $totals['ownWeak']);
        self::assertSame(5, $totals['thirdPartyStrong']);
        self::assertSame(0, $totals['thirdPartyWeak']);
    }
}
