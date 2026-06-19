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

/**
 * LogTrimmer.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class LogTrimmer
{
    private const MARKER = '…[truncated]';

    public static function message(string $message, int $limit): string
    {
        return mb_strlen($message) > $limit ? mb_substr($message, 0, $limit).self::MARKER : $message;
    }

    /**
     * Cap both the (possibly trace-inlined) message and the separate trace field.
     * A trace limit of 0 leaves the trace untouched.
     *
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    public static function entry(array $entry, int $messageLimit, int $traceLimit): array
    {
        $changes = [];
        if (isset($entry['message'])) {
            $changes['message'] = self::message(Cast::string($entry['message']), $messageLimit);
        }
        if (0 !== $traceLimit && isset($entry['trace'])) {
            $trace = Cast::string($entry['trace']);
            if (mb_strlen($trace) > $traceLimit) {
                $changes['trace'] = mb_substr($trace, 0, $traceLimit).self::MARKER;
            }
        }

        return [] === $changes ? $entry : array_merge($entry, $changes);
    }
}
