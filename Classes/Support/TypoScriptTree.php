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
     * Cap for the sibling list a miss reports. A branch like tt_content carries
     * one key per record type, and the point is to orient the caller, not to
     * dump the branch it did not ask for.
     */
    private const SIBLING_LIMIT = 40;

    /**
     * Substrings (matched against the delimiter-stripped, lowercased key) that
     * mark a resolved value as a secret. TypoScript constants/setup routinely
     * carry SMTP, API and payment credentials.
     */
    private const SECRET_KEY_PATTERNS = ['password', 'passwd', 'secret', 'apikey', 'token', 'credential', 'privatekey'];

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
        $walk = self::walk($tree, $path);

        return $walk['complete'] ? $walk['node'] : null;
    }

    /**
     * Like {@see get()} but a miss is an answer rather than an empty result: it
     * names how far the path resolved and which keys live there, so the caller
     * can correct the path instead of spending another turn guessing.
     *
     * @param array<mixed> $tree
     * @param string       $label what was searched, for the hint prose
     */
    public static function scope(array $tree, string $path, string $label = 'resolved TypoScript'): mixed
    {
        $walk = self::walk($tree, $path);
        if ($walk['complete']) {
            return $walk['node'];
        }

        $resolvedUpTo = '' === $walk['resolved'] ? null : $walk['resolved'];
        $siblings = self::childKeys($walk['node']);

        return [
            'path' => $path,
            'found' => false,
            'resolvedUpTo' => $resolvedUpTo,
            'siblingCount' => count($siblings),
            'siblings' => array_slice($siblings, 0, self::SIBLING_LIMIT),
            '_hint' => self::missHint($path, $label, $resolvedUpTo, $walk['node'], count($siblings)),
        ];
    }

    /**
     * Walk a dotted path as far as it resolves, reporting the deepest node
     * reached and whether every segment matched.
     *
     * @param array<mixed> $tree
     *
     * @return array{node: mixed, resolved: string, complete: bool}
     */
    private static function walk(array $tree, string $path): array
    {
        $node = $tree;
        $resolved = [];
        foreach (explode('.', trim($path, '.')) as $segment) {
            if (is_array($node) && array_key_exists($segment.'.', $node)) {
                $node = $node[$segment.'.'];
            } elseif (is_array($node) && array_key_exists($segment, $node)) {
                $node = $node[$segment];
            } else {
                return ['node' => $node, 'resolved' => implode('.', $resolved), 'complete' => false];
            }
            $resolved[] = $segment;
        }

        return ['node' => $node, 'resolved' => implode('.', $resolved), 'complete' => true];
    }

    /**
     * Child keys of a node with TypoScript's trailing object dot stripped, so
     * they can be appended to a dotted path verbatim. An object and a scalar of
     * the same name (foo. and foo) collapse into one entry.
     *
     * @return list<string>
     */
    private static function childKeys(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }

        $unique = [];
        foreach (array_keys($node) as $key) {
            $unique[rtrim((string) $key, '.')] = true;
        }

        $keys = array_map('strval', array_keys($unique));
        sort($keys, \SORT_NATURAL | \SORT_FLAG_CASE);

        return $keys;
    }

    private static function missHint(string $path, string $label, ?string $resolvedUpTo, mixed $node, int $siblingCount): string
    {
        if (null !== $resolvedUpTo && !is_array($node)) {
            return sprintf('"%s" does not exist in the %s: "%s" is a value, not a branch.', $path, $label, $resolvedUpTo);
        }

        $hint = null === $resolvedUpTo
            ? sprintf('"%s" does not exist in the %s. siblings are the top-level keys — pick one and drill in with a dotted path.', $path, $label)
            : sprintf('"%s" does not exist in the %s, but "%s" does. siblings are the keys directly below it.', $path, $label, $resolvedUpTo);

        return $siblingCount > self::SIBLING_LIMIT
            ? $hint.sprintf(' Showing the first %d of %d.', self::SIBLING_LIMIT, $siblingCount)
            : $hint;
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
