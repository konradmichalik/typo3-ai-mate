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

use KonradMichalik\Typo3AiMate\Support\ProfileTriage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ProfileTriageTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ProfileTriageTest extends TestCase
{
    #[Test]
    public function aFailedRequestRanksAboveASlowOneAndUnremarkableProfilesAreLeftOut(): void
    {
        $ranked = ProfileTriage::rank([
            $this->profile('fast', 10, 2),
            $this->profile('slow', 500, 3),
            $this->profile('fast2', 12, 2),
            ['token' => 'failed', 'status' => 500, 'timing' => ['total_ms' => 11], 'queries' => ['count' => 2]],
        ]);

        self::assertSame(['failed', 'slow'], array_map(static fn (array $entry): mixed => $entry['profile']['token'], $ranked));
        self::assertSame(['HTTP 500'], $ranked[0]['reasons']);
        self::assertSame(['500 ms, 43.5x the median of 4 profiles'], $ranked[1]['reasons']);
    }

    #[Test]
    public function aQueryCountFarAboveTheMedianIsFlagged(): void
    {
        $ranked = ProfileTriage::rank([
            $this->profile('a', 10, 2),
            $this->profile('b', 10, 3),
            $this->profile('c', 10, 30),
        ]);

        self::assertSame(['30 queries, 10.0x the median of 3 profiles'], $ranked[0]['reasons']);
    }

    #[Test]
    public function errorsExceptionsDeprecationsAndNPlusOneAreNamed(): void
    {
        $ranked = ProfileTriage::rank([[
            'token' => 'x',
            'status' => 200,
            'exception' => ['class' => 'RuntimeException', 'file' => '/x.php', 'line' => 1],
            'log' => [
                'count' => 6,
                'by_level' => ['critical' => 1, 'error' => 2, 'notice' => 3],
                'top_components' => [['component' => 'TYPO3.CMS.deprecations', 'count' => 3]],
            ],
            'duplicate_queries' => [['sql' => 'SELECT x', 'count' => 25, 'total_ms' => 3.0]],
        ]]);

        self::assertSame([
            'threw RuntimeException',
            '3 error-level log entries',
            '3 deprecations logged',
            'N+1 suspect: one query ran 25 times',
        ], $ranked[0]['reasons']);
    }

    #[Test]
    public function aDuplicateQueryBelowTheNPlusOneThresholdIsNotFlagged(): void
    {
        self::assertSame([], ProfileTriage::rank([
            ['token' => 'x', 'duplicate_queries' => [['sql' => 'SELECT x', 'count' => 3, 'total_ms' => 1.0]]],
        ]));
    }

    #[Test]
    public function profilesWithEqualSeverityAreOrderedByTheNumberOfReasonsThenByDuration(): void
    {
        $ranked = ProfileTriage::rank([
            ['token' => 'one', 'status' => 500, 'timing' => ['total_ms' => 50]],
            ['token' => 'two', 'status' => 503, 'timing' => ['total_ms' => 80]],
            ['token' => 'three', 'status' => 500, 'timing' => ['total_ms' => 10], 'log' => ['by_level' => ['error' => 1]]],
        ]);

        self::assertSame(['three', 'two', 'one'], array_map(static fn (array $entry): mixed => $entry['profile']['token'], $ranked));
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(string $token, int $totalMs, int $queries): array
    {
        return ['token' => $token, 'status' => 200, 'timing' => ['total_ms' => $totalMs], 'queries' => ['count' => $queries]];
    }
}
