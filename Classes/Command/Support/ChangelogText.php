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

use function array_filter;
use function array_map;
use function array_values;
use function intdiv;
use function max;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function preg_split;
use function str_contains;
use function strpos;
use function strtolower;
use function trim;

/**
 * ChangelogText.
 *
 * Matching and excerpting on the text of a core changelog RST file.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ChangelogText
{
    private const EXCERPT_LENGTH = 400;
    private const FILENAME_PATTERN = '/^(Breaking|Deprecation|Feature|Important)-(\d+)-/';
    private const HEADLINE_PATTERN = '/^(?:Breaking|Deprecation|Feature|Important): #\d+ - (.+)$/m';

    /**
     * @return list<string> lowercased, non-empty search words
     */
    public static function queryWords(string $query): array
    {
        $words = preg_split('/\s+/', trim($query)) ?: [];

        return array_values(array_filter(
            array_map(strtolower(...), $words),
            static fn (string $word): bool => '' !== $word,
        ));
    }

    /**
     * @param list<string> $words
     */
    public static function matchesAllWords(string $haystackLower, array $words): bool
    {
        if ([] === $words) {
            return false;
        }
        foreach ($words as $word) {
            if (!str_contains($haystackLower, $word)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{type: string, issue: int}|null
     */
    public static function parseFilename(string $filename): ?array
    {
        if (1 !== preg_match(self::FILENAME_PATTERN, $filename, $matches)) {
            return null;
        }

        return ['type' => $matches[1], 'issue' => (int) $matches[2]];
    }

    /**
     * The human-readable RST headline (e.g. "Populate extension title from
     * composer.json"), not the PascalCase filename fragment.
     */
    public static function extractTitle(string $content): ?string
    {
        if (1 !== preg_match(self::HEADLINE_PATTERN, $content, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * A length-bounded window of the content around the first matching word.
     *
     * @param list<string> $words
     */
    public static function excerpt(string $content, array $words): string
    {
        $lowerContent = strtolower($content);
        $earliest = null;
        foreach ($words as $word) {
            $position = strpos($lowerContent, $word);
            if (false !== $position && (null === $earliest || $position < $earliest)) {
                $earliest = $position;
            }
        }
        $earliest ??= 0;

        $start = max(0, $earliest - intdiv(self::EXCERPT_LENGTH, 2));
        $excerpt = trim(mb_substr($content, $start, self::EXCERPT_LENGTH));

        $prefix = $start > 0 ? '…' : '';
        $suffix = $start + self::EXCERPT_LENGTH < mb_strlen($content) ? '…' : '';

        return $prefix.$excerpt.$suffix;
    }
}
