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
use function count;
use function is_array;
use function is_scalar;
use function mb_strlen;
use function mb_substr;
use function sprintf;
use function str_contains;

/**
 * TypoScriptTree.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TypoScriptTree
{
    private const PREVIEW_LIMIT = 80;

    /**
     * A shallow, top-level overview of a resolved tree: each key mapped to a
     * short descriptor (child count for branches, a capped preview for scalars).
     * A full setup/TSconfig tree is often hundreds of kB; this keeps the default
     * output token-cheap while pointing the caller at how to drill in.
     *
     * @param array<mixed> $tree
     *
     * @return array<string, string>
     */
    public static function summarize(array $tree): array
    {
        $out = [];
        foreach ($tree as $key => $value) {
            $out[(string) $key] = is_array($value)
                ? sprintf('{%d keys}', count($value))
                : self::previewScalar($value);
        }
        $out['_hint'] = 'Top-level overview only. Pass a dotted path to drill into a branch, or full=true for the entire tree.';

        return $out;
    }

    /**
     * Substrings (matched against the delimiter-stripped, lowercased key) that
     * mark a resolved value as a secret. TypoScript constants/setup routinely
     * carry SMTP, API and payment credentials.
     */
    private const SECRET_KEY_PATTERNS = ['password', 'passwd', 'secret', 'apikey', 'token', 'credential', 'privatekey'];

    /**
     * Recursively mask scalar values whose key names a secret, so resolved
     * credentials never reach the AI client. Array nodes are descended into.
     *
     * @param array<mixed> $tree
     *
     * @return array<mixed>
     */
    public static function redactSecrets(array $tree): array
    {
        $out = [];
        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $out[$key] = self::redactSecrets($value);
            } elseif (is_scalar($value) && self::isSecretKey((string) $key)) {
                $out[$key] = '***';
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Resolve a dotted path within a resolved tree, returning null when the path
     * does not exist (or descends into a scalar).
     *
     * @param array<mixed> $tree
     */
    public static function get(array $tree, string $path): mixed
    {
        $node = $tree;
        foreach (explode('.', trim($path, '.')) as $segment) {
            if (is_array($node) && array_key_exists($segment.'.', $node)) {
                $node = $node[$segment.'.'];
            } elseif (is_array($node) && array_key_exists($segment, $node)) {
                $node = $node[$segment];
            } else {
                return null;
            }
        }

        return $node;
    }

    /**
     * Like {@see get()} but returns a structured error envelope instead of null,
     * for tools that surface the miss directly to the assistant.
     *
     * @param array<mixed> $tree
     */
    public static function scope(array $tree, string $path): mixed
    {
        $node = self::get($tree, $path);

        return $node ?? ['error' => sprintf('Path "%s" not found in resolved TypoScript.', $path)];
    }

    private static function previewScalar(mixed $value): string
    {
        $string = is_scalar($value) ? (string) $value : '';

        return mb_strlen($string) > self::PREVIEW_LIMIT ? mb_substr($string, 0, self::PREVIEW_LIMIT).'…' : $string;
    }

    private static function isSecretKey(string $key): bool
    {
        $normalized = str_replace([' ', '_', '.'], '', strtolower($key));
        foreach (self::SECRET_KEY_PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
