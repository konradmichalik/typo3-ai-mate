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

namespace KonradMichalik\Typo3AiMate\Service;

use KonradMichalik\Typo3AiMate\Support\{Cast, TypoScriptTree};
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function is_scalar;

/**
 * FluidResolver.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class FluidResolver
{
    public function __construct(private TypoScriptResolver $typoScript) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(int $pageId, string $viewPath, ?string $template, ?string $partial, ?string $layout, string $format): array
    {
        $setup = $this->typoScript->resolveSetup($pageId);
        $names = [
            'templateRootPaths' => $template,
            'partialRootPaths' => $partial,
            'layoutRootPaths' => $layout,
        ];

        $result = ['viewPath' => $viewPath, 'resolved' => []];
        foreach ($names as $kind => $name) {
            $candidates = $this->describe(
                self::orderedPaths(Cast::array(TypoScriptTree::get($setup, $viewPath.'.view.'.$kind))),
            );
            $result[$kind] = $candidates;

            if (null !== $name && '' !== $name) {
                $result['resolved'][$kind] = self::pickExisting($candidates, $name, $format);
            }
        }

        return $result;
    }

    /**
     * Order raw *RootPaths by numeric key descending — Fluid resolves the highest
     * key first, lower keys are fallbacks.
     *
     * @param array<mixed> $raw
     *
     * @return list<array{key: string, path: string}>
     */
    public static function orderedPaths(array $raw): array
    {
        $ordered = [];
        foreach ($raw as $key => $value) {
            if (is_scalar($value)) {
                $ordered[] = ['key' => (string) $key, 'path' => (string) $value];
            }
        }

        usort($ordered, static fn (array $a, array $b): int => (int) $b['key'] <=> (int) $a['key']);

        return $ordered;
    }

    /**
     * First candidate directory (already ordered) that contains <name>.<format>.
     *
     * @param list<array{absolute: string, ...}> $candidates
     *
     * @return array{file: string|null, checked: list<string>}
     */
    public static function pickExisting(array $candidates, string $name, string $format): array
    {
        $relative = ltrim(str_replace('\\', '/', $name), '/');
        $checked = [];
        foreach ($candidates as $candidate) {
            $base = rtrim($candidate['absolute'], '/');
            if ('' === $base) {
                continue;
            }
            $file = $base.'/'.$relative.'.'.$format;
            $checked[] = $file;
            if (is_file($file)) {
                return ['file' => $file, 'checked' => $checked];
            }
        }

        return ['file' => null, 'checked' => $checked];
    }

    /**
     * Enrich ordered paths with their absolute location and existence.
     *
     * @param list<array{key: string, path: string}> $ordered
     *
     * @return list<array{key: string, path: string, absolute: string, exists: bool}>
     */
    private function describe(array $ordered): array
    {
        return array_map(static function (array $entry): array {
            $absolute = GeneralUtility::getFileAbsFileName($entry['path']);

            return [
                'key' => $entry['key'],
                'path' => $entry['path'],
                'absolute' => $absolute,
                'exists' => '' !== $absolute && is_dir($absolute),
            ];
        }, $ordered);
    }
}
