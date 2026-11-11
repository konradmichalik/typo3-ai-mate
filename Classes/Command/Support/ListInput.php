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

use function array_slice;
use function count;
use function is_string;
use function str_contains;

/**
 * ListInput.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ListInput
{
    private const SUGGESTION_LIMIT = 5;

    /**
     * Levenshtein distance beyond which a name is a different one rather than a
     * typo of the one asked for.
     */
    private const SUGGESTION_DISTANCE = 6;

    /**
     * @return list<string>
     */
    public static function parse(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(trim(...), explode(',', $value)),
            static fn (string $item): bool => '' !== $item,
        )));
    }

    /**
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    public static function matching(string $pattern, array $candidates): array
    {
        $pattern = strtolower($pattern);
        $matches = array_values(array_filter(
            $candidates,
            static fn (string $candidate): bool => str_contains(strtolower($candidate), $pattern),
        ));
        sort($matches);

        return $matches;
    }

    /**
     * The candidates closest to a miss, so a wrong guess is answered rather than
     * merely denied.
     *
     * @param list<string> $candidates
     *
     * @return list<string>
     */
    public static function suggest(string $name, array $candidates): array
    {
        // A substring match is a better signal than edit distance for the common
        // "I remembered part of the name" case, so it goes first.
        $contained = self::matching($name, $candidates);
        if (count($contained) >= self::SUGGESTION_LIMIT) {
            return array_slice($contained, 0, self::SUGGESTION_LIMIT);
        }

        $scored = [];
        foreach ($candidates as $candidate) {
            $distance = levenshtein($name, $candidate);
            if ($distance <= self::SUGGESTION_DISTANCE) {
                $scored[$candidate] = $distance;
            }
        }
        asort($scored);

        return array_slice(array_values(array_unique([...$contained, ...array_keys($scored)])), 0, self::SUGGESTION_LIMIT);
    }
}
