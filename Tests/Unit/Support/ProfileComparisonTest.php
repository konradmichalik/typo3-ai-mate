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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Support;

use KonradMichalik\Typo3AiMate\Support\ProfileComparison;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ProfileComparisonTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ProfileComparisonTest extends TestCase
{
    #[Test]
    public function largestDifferencesAreRankedByHowManyTimesAMetricChanged(): void
    {
        $comparison = ProfileComparison::compare(
            ['timing' => ['total_ms' => 100], 'queries' => ['count' => 10], 'memory' => ['peak_mb' => 20]],
            ['timing' => ['total_ms' => 500], 'queries' => ['count' => 40], 'memory' => ['peak_mb' => 21]],
        );

        self::assertSame(
            ['timing.total_ms', 'queries.count', 'memory.peak_mb'],
            array_column($comparison['largest_differences'], 'metric'),
        );
        self::assertSame(
            ['metric' => 'timing.total_ms', 'a' => 100, 'b' => 500, 'delta' => 400, 'change_pct' => 400.0],
            $comparison['largest_differences'][0],
        );
    }

    #[Test]
    public function aLogSectionMissingFromOneProfileCountsAsZeroEntries(): void
    {
        $comparison = ProfileComparison::compare(
            [],
            ['log' => ['count' => 3, 'by_level' => ['error' => 2, 'warning' => 1]]],
        );

        self::assertSame(['a' => 0, 'b' => 2, 'delta' => 2, 'change_pct' => null], $comparison['metrics']['log.by_level.error']);
        self::assertSame(['a' => 0, 'b' => 3, 'delta' => 3, 'change_pct' => null], $comparison['metrics']['log.count']);
    }

    #[Test]
    public function aMetricRecordedInNeitherProfileIsLeftOut(): void
    {
        $comparison = ProfileComparison::compare(['timing' => ['total_ms' => 1]], ['timing' => ['total_ms' => 2]]);

        self::assertArrayNotHasKey('memory.peak_mb', $comparison['metrics']);
        self::assertArrayHasKey('timing.total_ms', $comparison['metrics']);
    }

    #[Test]
    public function identicalProfilesHaveNoLargestDifferences(): void
    {
        $profile = ['timing' => ['total_ms' => 100], 'queries' => ['count' => 10]];

        self::assertSame([], ProfileComparison::compare($profile, $profile)['largest_differences']);
    }

    #[Test]
    public function duplicateQueryPatternsAreMatchedBySqlAndRankedByTheirTimeDelta(): void
    {
        $comparison = ProfileComparison::compare(
            ['duplicate_queries' => [['sql' => 'SELECT x', 'count' => 2, 'total_ms' => 1.0]]],
            ['duplicate_queries' => [
                ['sql' => 'SELECT y', 'count' => 3, 'total_ms' => 2.0],
                ['sql' => 'SELECT x', 'count' => 30, 'total_ms' => 15.0, 'origin' => 'Foo.php:12'],
            ]],
        );

        self::assertSame([
            ['sql' => 'SELECT x', 'origin' => 'Foo.php:12', 'a_count' => 2, 'b_count' => 30, 'delta_count' => 28, 'delta_ms' => 14.0],
            ['sql' => 'SELECT y', 'origin' => null, 'a_count' => 0, 'b_count' => 3, 'delta_count' => 3, 'delta_ms' => 2.0],
        ], $comparison['contributors']['duplicate_queries']);
    }

    #[Test]
    public function logComponentsAndEventsAreReportedAsContributorsWhenTheyChanged(): void
    {
        $comparison = ProfileComparison::compare(
            ['log' => ['top_components' => [['component' => 'TYPO3.CMS.deprecations', 'count' => 1]]]],
            [
                'log' => ['top_components' => [['component' => 'TYPO3.CMS.deprecations', 'count' => 4]]],
                'events' => ['top' => [['event' => 'Foo\\BarEvent', 'count' => 2, 'total_ms' => 8.5]]],
            ],
        );

        self::assertSame(
            [['component' => 'TYPO3.CMS.deprecations', 'a' => 1, 'b' => 4, 'delta' => 3]],
            $comparison['contributors']['log_components'],
        );
        self::assertSame(
            [['event' => 'Foo\\BarEvent', 'a_ms' => 0, 'b_ms' => 8.5, 'delta_ms' => 8.5]],
            $comparison['contributors']['events'],
        );
    }

    #[Test]
    public function unchangedContributorsAreLeftOut(): void
    {
        $profile = ['duplicate_queries' => [['sql' => 'SELECT x', 'count' => 2, 'total_ms' => 1.0]]];

        self::assertSame([], ProfileComparison::compare($profile, $profile)['contributors']);
    }

    #[Test]
    public function theCacheStateOfBothProfilesIsReportedWithTheChangedDisabledReasons(): void
    {
        $comparison = ProfileComparison::compare(
            ['cache' => ['hit' => true, 'cacheable' => true]],
            ['cache' => ['hit' => false, 'cacheable' => false, 'disabled_reasons' => ['EXT:foo: no_cache']]],
        );

        self::assertSame([
            'a' => ['hit' => true, 'cacheable' => true],
            'b' => ['hit' => false, 'cacheable' => false],
            'disabled_reasons_added' => ['EXT:foo: no_cache'],
            'disabled_reasons_removed' => [],
        ], $comparison['cache']);
    }
}
