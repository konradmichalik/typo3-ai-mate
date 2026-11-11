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

use function is_string;

/**
 * SinceInput.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class SinceInput
{
    /**
     * Resolve a --since value to a minimum unix timestamp. Accepts a relative
     * offset (e.g. 30m, 2h, 7d) or any date string parseable by strtotime.
     * Returns null when empty or unparseable (i.e. no lower time bound).
     */
    public static function timestamp(mixed $since): ?int
    {
        if (!is_string($since) || '' === trim($since)) {
            return null;
        }
        $since = trim($since);

        if (1 === preg_match('/^(\d+)\s*([smhd])$/i', $since, $matches)) {
            $unit = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][strtolower($matches[2])] ?? 1;

            return time() - ((int) $matches[1] * $unit);
        }

        $timestamp = strtotime($since);

        return false === $timestamp ? null : $timestamp;
    }
}
