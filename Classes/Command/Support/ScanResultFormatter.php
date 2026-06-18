<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <km@move-elevator.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Command\Support;

use KonradMichalik\Typo3AiMate\Support\Cast;

use function array_slice;
use function count;
use function in_array;

/**
 * ScanResultFormatter.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
final readonly class ScanResultFormatter
{
    private const MAX_MATCHES = 200;

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public function format(array $result, string $format): array
    {
        return 'full' === $format ? $this->full($result) : $this->summary($result);
    }

    /**
     * Individual matches with line content, capped at MAX_MATCHES.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public function full(array $result): array
    {
        $matches = Cast::array($result['matches'] ?? []);
        $truncated = count($matches) > self::MAX_MATCHES;

        return [...$result, 'mode' => 'full', 'matches' => $truncated ? array_slice($matches, 0, self::MAX_MATCHES) : array_values($matches), '_truncated' => $truncated];
    }

    /**
     * Matches grouped by indicator + message with an occurrence count and the
     * distinct files affected — no per-occurrence line content.
     *
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    public function summary(array $result): array
    {
        $grouped = [];
        foreach (Cast::array($result['matches'] ?? []) as $rawMatch) {
            $match = Cast::array($rawMatch);
            $indicator = Cast::string($match['indicator'] ?? '');
            $message = Cast::string($match['message'] ?? '');
            $file = Cast::string($match['file'] ?? '');
            $key = $indicator.'|'.$message;
            if (!isset($grouped[$key])) {
                $grouped[$key] = ['message' => $message, 'indicator' => $indicator, 'count' => 0, 'files' => []];
            }
            ++$grouped[$key]['count'];
            if ('' !== $file && !in_array($file, $grouped[$key]['files'], true)) {
                $grouped[$key]['files'][] = $file;
            }
        }

        $matches = array_values($grouped);
        usort($matches, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [...$result, 'mode' => 'summary', 'matches' => $matches];
    }

    /**
     * @param array<string, mixed> $result
     */
    public function hasMatches(array $result): bool
    {
        return Cast::int(Cast::array($result['statistics'] ?? [])['matchCount'] ?? 0) > 0;
    }

    /**
     * Aggregate strong/weak totals across all scanned extensions, split by origin.
     *
     * @param list<array<string, mixed>> $results
     *
     * @return array<string, int>
     */
    public function rollup(array $results): array
    {
        $totals = ['extensionsScanned' => count($results), 'extensionsWithMatches' => 0, 'ownStrong' => 0, 'ownWeak' => 0, 'thirdPartyStrong' => 0, 'thirdPartyWeak' => 0];
        foreach ($results as $result) {
            $statistics = Cast::array($result['statistics'] ?? []);
            $strong = Cast::int($statistics['strong'] ?? 0);
            $weak = Cast::int($statistics['weak'] ?? 0);
            if ($strong + $weak > 0) {
                ++$totals['extensionsWithMatches'];
            }
            $prefix = 'own' === ($result['origin'] ?? 'own') ? 'own' : 'thirdParty';
            $totals[$prefix.'Strong'] += $strong;
            $totals[$prefix.'Weak'] += $weak;
        }

        return $totals;
    }
}
