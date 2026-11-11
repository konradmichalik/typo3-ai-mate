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

use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;

/**
 * ProfileTriage.
 *
 * Ranks recorded request profiles by what is worth a look, using only what
 * the profiles already contain. Duration and query count are judged against
 * the median of the profiles at hand, since what counts as slow depends on
 * the installation.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ProfileTriage
{
    private const OUTLIER_FACTOR = 2.0;
    private const N_PLUS_ONE_THRESHOLD = 10;
    private const ERROR_LEVELS = ['emergency', 'alert', 'critical', 'error'];
    private const DEPRECATION_COMPONENT = 'TYPO3.CMS.deprecations';

    /**
     * Profiles without any reason are left out. Failures (5xx, exceptions,
     * error-level log entries) rank first, then the number of reasons, then
     * the duration.
     *
     * @param list<array<string, mixed>> $profiles
     *
     * @return list<array{profile: array<string, mixed>, reasons: list<string>}>
     */
    public static function rank(array $profiles): array
    {
        $durations = self::present(array_map(self::duration(...), $profiles));
        $queryCounts = self::present(array_map(self::queryCount(...), $profiles));

        $ranked = [];
        foreach ($profiles as $profile) {
            $failures = self::failures($profile);
            $reasons = [
                ...$failures,
                ...self::deprecations($profile),
                ...self::outlier(self::duration($profile), $durations, '%s ms'),
                ...self::outlier(self::queryCount($profile), $queryCounts, '%s queries'),
                ...self::nPlusOne($profile),
            ];
            if ([] !== $reasons) {
                $ranked[] = ['profile' => $profile, 'reasons' => $reasons, 'failed' => [] !== $failures];
            }
        }

        usort($ranked, static fn (array $x, array $y): int => [$y['failed'], count($y['reasons']), self::duration($y['profile']) ?? 0]
            <=> [$x['failed'], count($x['reasons']), self::duration($x['profile']) ?? 0]);

        return array_map(
            static fn (array $entry): array => ['profile' => $entry['profile'], 'reasons' => $entry['reasons']],
            $ranked,
        );
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return list<string>
     */
    private static function failures(array $profile): array
    {
        $reasons = [];
        $status = Cast::int($profile['status'] ?? 0);
        if ($status >= 500) {
            $reasons[] = 'HTTP '.$status;
        }
        if (is_array($profile['exception'] ?? null)) {
            $class = $profile['exception']['class'] ?? null;
            $reasons[] = is_string($class) ? 'threw '.$class : 'threw an exception';
        }
        $byLevel = Cast::array(Cast::array($profile['log'] ?? null)['by_level'] ?? null);
        $errors = 0;
        foreach (self::ERROR_LEVELS as $level) {
            $errors += Cast::int($byLevel[$level] ?? 0);
        }
        if ($errors > 0) {
            $reasons[] = self::counted($errors, 'error-level log entry', 'error-level log entries');
        }

        return $reasons;
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return list<string>
     */
    private static function deprecations(array $profile): array
    {
        foreach (Cast::array(Cast::array($profile['log'] ?? null)['top_components'] ?? null) as $component) {
            $component = Cast::array($component);
            if (self::DEPRECATION_COMPONENT === ($component['component'] ?? null)) {
                return [self::counted(Cast::int($component['count'] ?? 0), 'deprecation logged', 'deprecations logged')];
            }
        }

        return [];
    }

    /**
     * @param list<int|float> $population
     *
     * @return list<string>
     */
    private static function outlier(int|float|null $value, array $population, string $label): array
    {
        $median = self::median($population);
        if (null === $value || null === $median || $median <= 0 || $value < self::OUTLIER_FACTOR * $median) {
            return [];
        }

        $ratio = number_format($value / $median, 1, '.', '');

        return [sprintf($label.', %sx the median of %d profiles', Cast::string($value), $ratio, count($population))];
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return list<string>
     */
    private static function nPlusOne(array $profile): array
    {
        $worst = 0;
        foreach (Cast::array($profile['duplicate_queries'] ?? null) as $pattern) {
            $worst = max($worst, Cast::int(Cast::array($pattern)['count'] ?? 0));
        }

        return $worst >= self::N_PLUS_ONE_THRESHOLD ? [sprintf('N+1 suspect: one query ran %d times', $worst)] : [];
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function duration(array $profile): int|float|null
    {
        return self::number(Cast::array($profile['timing'] ?? null)['total_ms'] ?? null);
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function queryCount(array $profile): int|float|null
    {
        return self::number(Cast::array($profile['queries'] ?? null)['count'] ?? null);
    }

    /**
     * @param list<int|float> $values
     */
    private static function median(array $values): int|float|null
    {
        if ([] === $values) {
            return null;
        }
        sort($values);
        $middle = intdiv(count($values), 2);

        return 0 === count($values) % 2 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle];
    }

    /**
     * @param list<int|float|null> $values
     *
     * @return list<int|float>
     */
    private static function present(array $values): array
    {
        return array_values(array_filter($values, static fn (int|float|null $value): bool => null !== $value));
    }

    private static function number(mixed $value): int|float|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }

    private static function counted(int $count, string $singular, string $plural): string
    {
        return $count.' '.(1 === $count ? $singular : $plural);
    }
}
