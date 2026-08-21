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

use function array_slice;
use function count;
use function is_string;
use function str_contains;

/**
 * IconLookup.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class IconLookup
{
    private const SUGGESTION_LIMIT = 5;

    /**
     * Levenshtein distance beyond which an identifier is a different icon rather
     * than a typo of the one asked for.
     */
    private const SUGGESTION_DISTANCE = 6;

    /**
     * @return list<string>
     */
    public static function parseList(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $identifier): bool => '' !== $identifier,
        )));
    }

    /**
     * Registered identifiers whose leading segment groups them, e.g. 812 for
     * `actions`. An orientation answer for a set that runs into the thousands.
     *
     * @param list<string> $registered
     *
     * @return array<string, int>
     */
    public static function groupCounts(array $registered): array
    {
        $groups = [];
        foreach ($registered as $identifier) {
            $group = explode('-', $identifier, 2)[0];
            $groups[$group] = ($groups[$group] ?? 0) + 1;
        }
        ksort($groups);

        return $groups;
    }

    /**
     * @param list<string> $registered
     *
     * @return list<string>
     */
    public static function matching(string $pattern, array $registered): array
    {
        $pattern = strtolower($pattern);
        $matches = array_values(array_filter(
            $registered,
            static fn (string $identifier): bool => str_contains(strtolower($identifier), $pattern),
        ));
        sort($matches);

        return $matches;
    }

    /**
     * The registered identifiers closest to a miss, so a wrong guess is answered
     * rather than merely denied.
     *
     * @param list<string> $registered
     *
     * @return list<string>
     */
    public static function suggest(string $identifier, array $registered): array
    {
        // A substring match is a better signal than edit distance for the common
        // "I remembered part of the name" case, so it goes first.
        $contained = self::matching($identifier, $registered);
        if (count($contained) >= self::SUGGESTION_LIMIT) {
            return array_slice($contained, 0, self::SUGGESTION_LIMIT);
        }

        $scored = [];
        foreach ($registered as $candidate) {
            $distance = levenshtein($identifier, $candidate);
            if ($distance <= self::SUGGESTION_DISTANCE) {
                $scored[$candidate] = $distance;
            }
        }
        asort($scored);

        return array_slice(array_values(array_unique([...$contained, ...array_keys($scored)])), 0, self::SUGGESTION_LIMIT);
    }

    /**
     * @param array<mixed> $configuration
     */
    public static function source(array $configuration): string
    {
        $options = Cast::array($configuration['options'] ?? null);

        // Where an icon comes from depends on its provider: core's T3Icons are
        // sprite references (EXT:core/…/sprites/actions.svg#actions-plus), a
        // bitmap or standalone SVG icon carries a file path, a font icon a glyph
        // name.
        return Cast::string($options['sprite'] ?? $options['source'] ?? $options['name'] ?? '');
    }

    /**
     * The extension key from an EXT: source path, which is the only place the
     * registry records where an icon came from.
     */
    public static function providingExtension(string $source): ?string
    {
        if (!str_starts_with($source, 'EXT:')) {
            return null;
        }
        $key = explode('/', substr($source, 4), 2)[0];

        return '' !== $key ? $key : null;
    }

    /**
     * The provider's short class name; the FQCN says nothing extra here.
     */
    public static function providerName(string $provider): string
    {
        if ('' === $provider) {
            return '';
        }
        $parts = explode('\\', $provider);

        return end($parts);
    }
}
