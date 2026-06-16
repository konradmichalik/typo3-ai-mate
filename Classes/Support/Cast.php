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

namespace KonradMichalik\Typo3AiMate\Support;

use function is_array;
use function is_scalar;

/**
 * Cast.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final class Cast
{
    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return array<mixed>
     */
    public static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
