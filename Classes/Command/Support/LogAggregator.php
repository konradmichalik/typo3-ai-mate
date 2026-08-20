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

namespace KonradMichalik\Typo3AiMate\Command\Support;

use KonradMichalik\Typo3AiMate\Support\Cast;

use function array_map;
use function array_shift;
use function array_slice;
use function array_values;
use function count;

/**
 * LogAggregator.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class LogAggregator
{
    public function __construct(private int $messageLimit) {}

    /**
     * @param iterable<array<string, mixed>> $entries
     *
     * @return array{mode: string, totalMatched: int, distinct: int, entries: list<array<string, mixed>>}
     */
    public function summary(iterable $entries, int $limit): array
    {
        $grouped = [];
        $total = 0;
        foreach ($entries as $entry) {
            ++$total;
            $grouped = $this->accumulate($grouped, $entry);
        }
        $summaries = $this->sortSummaries($grouped);

        return [
            'mode' => 'summary',
            'totalMatched' => $total,
            'distinct' => count($summaries),
            'entries' => array_slice($summaries, 0, $limit),
        ];
    }

    /**
     * @param iterable<array<string, mixed>> $entries
     *
     * @return array{mode: string, totalMatched: int, entries: list<array<string, mixed>>}
     */
    public function recent(iterable $entries, int $limit, int $traceLimit): array
    {
        $window = [];
        $total = 0;
        foreach ($entries as $entry) {
            ++$total;
            $window[] = $entry;
            if (count($window) > $limit) {
                array_shift($window);
            }
        }

        return [
            'mode' => 'full',
            'totalMatched' => $total,
            'entries' => array_map(fn (array $entry): array => LogTrimmer::entry($entry, $this->messageLimit, $traceLimit), $window),
        ];
    }

    /**
     * Deduplicated summaries only (no payload wrapper), for callers that already
     * hold a bounded entry list.
     *
     * @param iterable<array<string, mixed>> $entries
     *
     * @return list<array{message: string, level: string, component: string, count: int, lastSeen: string, exampleRequestId: string}>
     */
    public function summaries(iterable $entries): array
    {
        $grouped = [];
        foreach ($entries as $entry) {
            $grouped = $this->accumulate($grouped, $entry);
        }

        return $this->sortSummaries($grouped);
    }

    /**
     * Fold one entry into the message-keyed summary map. Grouping by the capped
     * message means near-identical entries whose only difference is deep in an
     * inlined trace collapse into one.
     *
     * @param array<string, array{message: string, level: string, component: string, count: int, lastSeen: string, exampleRequestId: string}> $grouped
     * @param array<string, mixed>                                                                                                            $entry
     *
     * @return array<string, array{message: string, level: string, component: string, count: int, lastSeen: string, exampleRequestId: string}>
     */
    private function accumulate(array $grouped, array $entry): array
    {
        $message = LogTrimmer::message(Cast::string($entry['message'] ?? ''), $this->messageLimit);
        if ('' === $message) {
            return $grouped;
        }
        if (!isset($grouped[$message])) {
            $grouped[$message] = [
                'message' => $message,
                'level' => Cast::string($entry['level'] ?? ''),
                'component' => Cast::string($entry['component'] ?? ''),
                'count' => 0,
                'lastSeen' => '',
                'exampleRequestId' => Cast::string($entry['request_id'] ?? ''),
            ];
        }
        ++$grouped[$message]['count'];
        $grouped[$message]['lastSeen'] = Cast::string($entry['time'] ?? '');

        return $grouped;
    }

    /**
     * @param array<string, array{message: string, level: string, component: string, count: int, lastSeen: string, exampleRequestId: string}> $grouped
     *
     * @return list<array{message: string, level: string, component: string, count: int, lastSeen: string, exampleRequestId: string}>
     */
    private function sortSummaries(array $grouped): array
    {
        $summaries = array_values($grouped);
        usort($summaries, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $summaries;
    }
}
