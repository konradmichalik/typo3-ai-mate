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

/**
 * Redactor.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class Redactor
{
    /**
     * Mask the value of a `key=value` / `key: value` pair whose key names a
     * secret, keeping the key so the context stays readable.
     */
    private const SECRET_KV = '/((?:password|passwd|pwd|secret|token|api[_-]?key|access[_-]?token|refresh[_-]?token|authorization|credential)["\']?\s*[:=]\s*["\']?)([^\s"\'&,;]+)/i';

    private const EMAIL = '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.[\p{L}]{2,}/u';

    /**
     * Four dotted octets. Version strings are rarely four-part, so the small
     * over-redaction risk is acceptable for a privacy default.
     */
    private const IPV4 = '/\b(?:\d{1,3}\.){3}\d{1,3}\b/';

    /**
     * Strip personal data and credentials from a free-text string (log message,
     * stack trace) before it reaches the AI client or its conversation log.
     */
    public static function redact(string $value): string
    {
        $value = preg_replace(self::SECRET_KV, '$1[redacted]', $value) ?? $value;
        $value = preg_replace(self::EMAIL, '[redacted-email]', $value) ?? $value;

        return preg_replace(self::IPV4, '[redacted-ip]', $value) ?? $value;
    }
}
