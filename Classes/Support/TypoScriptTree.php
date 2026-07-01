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
use function is_array;
use function sprintf;

/**
 * TypoScriptTree.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TypoScriptTree
{
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

        return null === $node
            ? ['error' => sprintf('Path "%s" not found in resolved TypoScript.', $path)]
            : $node;
    }
}
