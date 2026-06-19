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

use KonradMichalik\Typo3AiMate\Log\DeprecationBacktraceProcessor;

/**
 * DeprecationLogData.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class DeprecationLogData
{
    public static function origin(string $message): ?string
    {
        if (1 !== preg_match('/"'.preg_quote(DeprecationBacktraceProcessor::DATA_KEY, '/').'":"([^"]+)"/', $message, $matches)) {
            return null;
        }

        // FileWriter json_encode escapes slashes (\/) by default.
        return str_replace('\\/', '/', $matches[1]);
    }

    public static function withoutData(string $message): string
    {
        return rtrim((string) preg_replace('/ - \{.*\}\s*$/', '', $message));
    }
}
