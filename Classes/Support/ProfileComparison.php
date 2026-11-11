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

namespace KonradMichalik\Typo3AiMate\Support;

use function array_key_exists;
use function array_slice;
use function count;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;

/**
 * ProfileComparison.
 *
 * Diffs two recorded request profiles metric by metric, using only what the
 * profiles already contain.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ProfileComparison
{
    private const MAX_DIFFERENCES = 5;
    private const MAX_CONTRIBUTORS = 5;

    private const METRICS = [
        'timing.total_ms',
        'memory.peak_mb',
        'queries.count',
        'queries.total_ms',
        'http.count',
        'http.total_ms',
        'log.count',
        'php.included_files',
    ];

    /**
     * The profiler omits these sections on a request where nothing happened,
     * so their absence next to a recorded counterpart means zero, not unknown.
     */
    private const ZERO_WHEN_ABSENT = ['log', 'http', 'duplicate_queries'];

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return array{metrics: array<string, array{a: int|float|null, b: int|float|null, delta: int|float|null, change_pct: float|null}>, largest_differences: list<array<string, mixed>>, cache: array<string, mixed>, contributors: array<string, list<array<string, mixed>>>}
     */
    public static function compare(array $a, array $b): array
    {
        $metrics = self::metrics($a, $b);

        return [
            'metrics' => $metrics,
            'largest_differences' => self::largestDifferences($metrics),
            'cache' => self::cache($a, $b),
            'contributors' => self::contributors($a, $b),
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return array<string, array{a: int|float|null, b: int|float|null, delta: int|float|null, change_pct: float|null}>
     */
    private static function metrics(array $a, array $b): array
    {
        $values = [];
        foreach (self::METRICS as $path) {
            $values[$path] = [self::valueAt($a, $path), self::valueAt($b, $path)];
        }
        $values['duplicate_queries'] = [self::duplicatePatterns($a), self::duplicatePatterns($b)];
        $values['duplicate_queries.executions'] = [self::duplicateExecutions($a), self::duplicateExecutions($b)];
        foreach (self::logLevels($a, $b) as $level) {
            $values['log.by_level.'.$level] = [self::valueAt($a, 'log.by_level.'.$level), self::valueAt($b, 'log.by_level.'.$level)];
        }

        $metrics = [];
        foreach ($values as $path => [$valueA, $valueB]) {
            if (null === $valueA && null === $valueB) {
                continue;
            }
            $section = explode('.', $path)[0];
            if (in_array($section, self::ZERO_WHEN_ABSENT, true)) {
                $valueA ??= 0;
                $valueB ??= 0;
            }
            $metrics[$path] = self::entry($valueA, $valueB);
        }

        return $metrics;
    }

    /**
     * @return array{a: int|float|null, b: int|float|null, delta: int|float|null, change_pct: float|null}
     */
    private static function entry(int|float|null $a, int|float|null $b): array
    {
        if (null === $a || null === $b) {
            return ['a' => $a, 'b' => $b, 'delta' => null, 'change_pct' => null];
        }
        $delta = self::difference($a, $b);

        return [
            'a' => $a,
            'b' => $b,
            'delta' => $delta,
            'change_pct' => self::isZero($a) ? null : round($delta / $a * 100, 1),
        ];
    }

    /**
     * Ranked by fold change rather than absolute delta, so milliseconds and
     * query counts compete on one scale; the +1 keeps a change from zero finite.
     *
     * @param array<string, array{a: int|float|null, b: int|float|null, delta: int|float|null, change_pct: float|null}> $metrics
     *
     * @return list<array<string, mixed>>
     */
    private static function largestDifferences(array $metrics): array
    {
        $ranked = [];
        foreach ($metrics as $path => $entry) {
            if (null === $entry['a'] || null === $entry['b'] || null === $entry['delta'] || self::isZero($entry['delta'])) {
                continue;
            }
            $ranked[] = [
                'score' => abs(log((abs($entry['b']) + 1) / (abs($entry['a']) + 1))),
                'difference' => ['metric' => $path] + $entry,
            ];
        }
        usort($ranked, static fn (array $x, array $y): int => $y['score'] <=> $x['score']);

        return array_column(array_slice($ranked, 0, self::MAX_DIFFERENCES), 'difference');
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return array<string, mixed>
     */
    private static function cache(array $a, array $b): array
    {
        $cacheA = Cast::array($a['cache'] ?? null);
        $cacheB = Cast::array($b['cache'] ?? null);
        $reasonsA = array_filter(Cast::array($cacheA['disabled_reasons'] ?? null), is_string(...));
        $reasonsB = array_filter(Cast::array($cacheB['disabled_reasons'] ?? null), is_string(...));

        return [
            'a' => ['hit' => $cacheA['hit'] ?? null, 'cacheable' => $cacheA['cacheable'] ?? null],
            'b' => ['hit' => $cacheB['hit'] ?? null, 'cacheable' => $cacheB['cacheable'] ?? null],
            'disabled_reasons_added' => array_values(array_diff($reasonsB, $reasonsA)),
            'disabled_reasons_removed' => array_values(array_diff($reasonsA, $reasonsB)),
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function contributors(array $a, array $b): array
    {
        return array_filter([
            'duplicate_queries' => self::duplicateQueryContributors($a, $b),
            'log_components' => self::logComponentContributors($a, $b),
            'events' => self::eventContributors($a, $b),
        ], static fn (array $list): bool => [] !== $list);
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return list<array<string, mixed>>
     */
    private static function duplicateQueryContributors(array $a, array $b): array
    {
        $patternsA = self::indexBy($a['duplicate_queries'] ?? null, 'sql');
        $patternsB = self::indexBy($b['duplicate_queries'] ?? null, 'sql');

        $entries = [];
        foreach (array_keys($patternsB + $patternsA) as $sql) {
            $countA = self::number($patternsA[$sql]['count'] ?? null) ?? 0;
            $countB = self::number($patternsB[$sql]['count'] ?? null) ?? 0;
            $msA = self::number($patternsA[$sql]['total_ms'] ?? null) ?? 0;
            $msB = self::number($patternsB[$sql]['total_ms'] ?? null) ?? 0;
            $entries[] = [
                'sql' => $sql,
                'origin' => $patternsB[$sql]['origin'] ?? $patternsA[$sql]['origin'] ?? null,
                'a_count' => $countA,
                'b_count' => $countB,
                'delta_count' => self::difference($countA, $countB),
                'delta_ms' => self::difference($msA, $msB),
            ];
        }

        return self::largestChanges($entries, 'delta_ms', 'delta_count');
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return list<array<string, mixed>>
     */
    private static function logComponentContributors(array $a, array $b): array
    {
        $componentsA = self::indexBy(Cast::array($a['log'] ?? null)['top_components'] ?? null, 'component');
        $componentsB = self::indexBy(Cast::array($b['log'] ?? null)['top_components'] ?? null, 'component');

        $entries = [];
        foreach (array_keys($componentsB + $componentsA) as $component) {
            $countA = self::number($componentsA[$component]['count'] ?? null) ?? 0;
            $countB = self::number($componentsB[$component]['count'] ?? null) ?? 0;
            $entries[] = ['component' => $component, 'a' => $countA, 'b' => $countB, 'delta' => self::difference($countA, $countB)];
        }

        return self::largestChanges($entries, 'delta');
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return list<array<string, mixed>>
     */
    private static function eventContributors(array $a, array $b): array
    {
        $eventsA = self::indexBy(Cast::array($a['events'] ?? null)['top'] ?? null, 'event');
        $eventsB = self::indexBy(Cast::array($b['events'] ?? null)['top'] ?? null, 'event');

        $entries = [];
        foreach (array_keys($eventsB + $eventsA) as $event) {
            $msA = self::number($eventsA[$event]['total_ms'] ?? null) ?? 0;
            $msB = self::number($eventsB[$event]['total_ms'] ?? null) ?? 0;
            $entries[] = ['event' => $event, 'a_ms' => $msA, 'b_ms' => $msB, 'delta_ms' => self::difference($msA, $msB)];
        }

        return self::largestChanges($entries, 'delta_ms');
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array<string, mixed>>
     */
    private static function largestChanges(array $entries, string ...$fields): array
    {
        $changed = array_values(array_filter(
            $entries,
            static function (array $entry) use ($fields): bool {
                foreach ($fields as $field) {
                    if (!self::isZero(self::number($entry[$field]) ?? 0)) {
                        return true;
                    }
                }

                return false;
            },
        ));
        $magnitude = static fn (array $entry): array => array_map(
            static fn (string $field): int|float => abs(self::number($entry[$field]) ?? 0),
            $fields,
        );
        usort($changed, static fn (array $x, array $y): int => $magnitude($y) <=> $magnitude($x));

        return array_slice($changed, 0, self::MAX_CONTRIBUTORS);
    }

    /**
     * @return array<string, array<mixed>>
     */
    private static function indexBy(mixed $list, string $key): array
    {
        $indexed = [];
        foreach (Cast::array($list) as $item) {
            if (is_array($item) && is_string($item[$key] ?? null)) {
                $indexed[$item[$key]] = $item;
            }
        }

        return $indexed;
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return list<string>
     */
    private static function logLevelsOf(array $profile): array
    {
        return array_map(strval(...), array_keys(Cast::array(Cast::array($profile['log'] ?? null)['by_level'] ?? null)));
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return list<string>
     */
    private static function logLevels(array $a, array $b): array
    {
        return array_values(array_unique([...self::logLevelsOf($a), ...self::logLevelsOf($b)]));
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function duplicatePatterns(array $profile): ?int
    {
        return is_array($profile['duplicate_queries'] ?? null) ? count($profile['duplicate_queries']) : null;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function duplicateExecutions(array $profile): int|float|null
    {
        if (!is_array($profile['duplicate_queries'] ?? null)) {
            return null;
        }
        $total = 0;
        foreach ($profile['duplicate_queries'] as $pattern) {
            $total += self::number(Cast::array($pattern)['count'] ?? null) ?? 0;
        }

        return $total;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function valueAt(array $profile, string $path): int|float|null
    {
        $value = $profile;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return self::number($value);
    }

    private static function number(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }

    private static function isZero(int|float $value): bool
    {
        return 0 === $value || 0.0 === $value;
    }

    private static function difference(int|float $a, int|float $b): int|float
    {
        $delta = $b - $a;

        return is_float($delta) ? round($delta, 2) : $delta;
    }
}
