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

use KonradMichalik\Typo3AiMate\Support\Redactor;

use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function strtolower;

/**
 * ConfigRedactor.
 *
 * Recursively masks a TYPO3_CONF_VARS subtree before it reaches an assistant.
 * Two passes: any key that looks like a secret masks its whole value outright
 * (SYS/encryptionKey, a DB connection's password, …), since {@see Redactor}
 * is a string filter that cannot see array keys at all. {@see Redactor} then
 * runs over the remaining string leaves, for a credential embedded inside a
 * value rather than named by its key (a DSN, a connection string).
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ConfigRedactor
{
    /**
     * Substring match against the lowercased key. Deliberately broad — this
     * output lands in assistant conversation logs, so a false-positive mask
     * is a rounding error and a leaked secret is not.
     */
    private const SENSITIVE_KEY_PATTERN = '/pass|secret|token|credential|encryption|private/i';

    /**
     * Exact keys that name a secret without containing any of the substrings
     * above (e.g. "apiKey" has no "secret"/"token"/… fragment).
     */
    private const SENSITIVE_KEYS = ['key', 'apikey', 'authcode'];

    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                $redacted[$key] = self::isSensitiveKey((string) $key) ? '[redacted]' : self::redact($item);
            }

            return $redacted;
        }

        return is_string($value) ? Redactor::redact($value) : $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        return in_array($normalized, self::SENSITIVE_KEYS, true) || 1 === preg_match(self::SENSITIVE_KEY_PATTERN, $normalized);
    }
}
