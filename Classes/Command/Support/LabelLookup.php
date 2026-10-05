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

use function count;
use function dirname;
use function in_array;
use function is_array;

/**
 * LabelLookup.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class LabelLookup
{
    /**
     * Same pattern as core's TranslationDomainResolver::isValidDomainName() (v14),
     * duplicated so a domain reference is recognised on v13 too, where it is
     * answered with a hint instead of a lookup.
     */
    private const DOMAIN = '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/';

    private const LOCALE = '/^[a-z]{2,3}(?:[_-][A-Za-z]{2,4})?$/';

    /**
     * Splits `LLL:EXT:<ext>/<path>.xlf:<key>` or a v14 translation domain
     * reference `<domain>:<key>` into its file part and key. The LLL: prefix
     * is optional, matching what LanguageService::sL() accepts.
     *
     * @return array{file: string, key: string, domain: bool}|null
     */
    public static function parseReference(string $reference): ?array
    {
        $rest = trim($reference);
        if (str_starts_with($rest, 'LLL:')) {
            $rest = substr($rest, 4);
        }

        if (str_starts_with($rest, 'EXT:')) {
            $parts = explode(':', substr($rest, 4), 2);
            if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
                return null;
            }

            return ['file' => 'EXT:'.$parts[0], 'key' => $parts[1], 'domain' => false];
        }

        $parts = explode(':', $rest, 2);
        if (2 !== count($parts) || '' === $parts[1] || 1 !== preg_match(self::DOMAIN, $parts[0])) {
            return null;
        }

        return ['file' => $parts[0], 'key' => $parts[1], 'domain' => true];
    }

    public static function extensionKey(string $file): ?string
    {
        if (str_starts_with($file, 'EXT:')) {
            $key = explode('/', substr($file, 4), 2)[0];

            return '' !== $key ? $key : null;
        }

        return 1 === preg_match(self::DOMAIN, $file) ? explode('.', $file, 2)[0] : null;
    }

    /**
     * v13 shape: `[locale => [key => [0 => ['source' => …, 'target' => …]]]]`.
     *
     * @param array<mixed> $parsed
     *
     * @return array<string, string>
     */
    public static function flattenLegacy(array $parsed, string $locale): array
    {
        $labels = [];
        foreach (Cast::array($parsed[$locale] ?? null) as $key => $forms) {
            $form = Cast::array(Cast::array($forms)[0] ?? null);
            $labels[(string) $key] = Cast::string($form['target'] ?? $form['source'] ?? '');
        }

        return $labels;
    }

    /**
     * v14 shape: `[key => string|list<string>]`, a list being plural forms.
     *
     * @param array<mixed> $parsed
     *
     * @return array<string, string>
     */
    public static function flatten(array $parsed): array
    {
        $labels = [];
        foreach ($parsed as $key => $value) {
            $labels[(string) $key] = Cast::string(is_array($value) ? (array_values($value)[0] ?? '') : $value);
        }

        return $labels;
    }

    /**
     * Groups label file paths into the files that hold labels and the locales
     * translating each. A translation file is `<locale>.<name>` next to the
     * file it translates; without that sibling it is a label file of its own
     * (e.g. `db.xlf`).
     *
     * @param list<string> $paths
     *
     * @return array<string, list<string>> path => locales
     */
    public static function groupTranslations(array $paths): array
    {
        $files = [];
        $locales = [];
        foreach ($paths as $path) {
            $parts = explode('.', basename($path), 2);
            $translated = dirname($path).'/'.($parts[1] ?? '');
            if (isset($parts[1]) && self::isValidLocale($parts[0]) && in_array($translated, $paths, true)) {
                $locales[$translated][] = $parts[0];
                continue;
            }
            $files[] = $path;
        }

        $grouped = [];
        foreach ($files as $file) {
            $grouped[$file] = $locales[$file] ?? [];
        }

        return $grouped;
    }

    public static function isValidLocale(string $locale): bool
    {
        return 1 === preg_match(self::LOCALE, $locale);
    }
}
