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

use function array_key_exists;
use function array_slice;
use function count;
use function is_array;
use function is_scalar;
use function sprintf;

/**
 * FluidResolver.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class FluidResolver
{
    private const CANDIDATE_LIMIT = 50;

    /**
     * Depth cap for the view-path walk in {@see viewPathCandidates()}: view
     * configurations live near the top (plugin.tx_x.view, tt_content.x.20.view),
     * a resolved setup tree does not.
     */
    private const CANDIDATE_DEPTH = 8;

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

        $chains = [];
        foreach (array_keys($names) as $kind) {
            $chains[$kind] = $this->describe(
                self::orderedPaths(Cast::array(TypoScriptTree::get($setup, $viewPath.'.view.'.$kind))),
            );
        }

        $viewPathFound = [] !== array_filter($chains, static fn (array $chain): bool => [] !== $chain);

        $result = ['viewPath' => $viewPath, 'viewPathFound' => $viewPathFound];
        if (!$viewPathFound) {
            $result += self::missAnswer($setup, $viewPath, $pageId);
        }

        $result['resolved'] = [];
        foreach ($names as $kind => $name) {
            $result[$kind] = $chains[$kind];

            if (null !== $name && '' !== $name) {
                $result['resolved'][$kind] = self::pickExisting($chains[$kind], $name, $format);
            }
        }

        return $result;
    }

    /**
     * TypoScript paths in the resolved setup that declare at least one Fluid
     * root path, so a miss can name what it *can* resolve. The walk is depth
     * capped: a view configuration sits within a few segments of the top level
     * (plugin.tx_x.view, tt_content.x.20.view), while the resolved setup tree as
     * a whole goes far deeper than anything worth walking for this.
     *
     * @param array<mixed> $setup
     *
     * @return list<string>
     */
    public static function viewPathCandidates(array $setup): array
    {
        $paths = self::collectViewPaths($setup, '', 0);
        sort($paths, \SORT_NATURAL);

        return $paths;
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
     * A miss states found=false rather than only a null file, so a negative is
     * an answer and not an absent one.
     *
     * @param list<array{absolute: string, ...}> $candidates
     *
     * @return array{file: string|null, found: bool, checked: list<string>}
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

            // Guard against path traversal via the (user-supplied) name/format:
            // the resolved file must stay inside the candidate root directory.
            $real = realpath($file);
            $realBase = realpath($base);
            if (false === $real || false === $realBase || !str_starts_with($real, $realBase.\DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (is_file($real)) {
                return ['file' => $file, 'found' => true, 'checked' => $checked];
            }
        }

        return ['file' => null, 'found' => false, 'checked' => $checked];
    }

    /**
     * What a caller needs when the view path resolves nothing: the paths that do,
     * rather than three empty override chains.
     *
     * @param array<mixed> $setup
     *
     * @return array<string, mixed>
     */
    private static function missAnswer(array $setup, string $viewPath, int $pageId): array
    {
        $candidates = self::viewPathCandidates($setup);
        $hint = sprintf(
            '"%s" declares no view.templateRootPaths/partialRootPaths/layoutRootPaths in the resolved TypoScript of page %d. candidates lists the view paths that do — pass one of them as plugin.',
            $viewPath,
            $pageId,
        );
        if (count($candidates) > self::CANDIDATE_LIMIT) {
            $hint .= sprintf(' Showing the first %d of %d.', self::CANDIDATE_LIMIT, count($candidates));
        }

        return [
            'candidateCount' => count($candidates),
            'candidates' => array_slice($candidates, 0, self::CANDIDATE_LIMIT),
            '_hint' => $hint,
        ];
    }

    /**
     * @param array<mixed> $node
     *
     * @return list<string>
     */
    private static function collectViewPaths(array $node, string $prefix, int $depth): array
    {
        if ($depth > self::CANDIDATE_DEPTH) {
            return [];
        }

        $found = [];
        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            $name = rtrim((string) $key, '.');
            if ('view' === $name) {
                if ('' !== $prefix && self::declaresRootPaths($value)) {
                    $found[] = $prefix;
                }
                continue;
            }

            $found = [...$found, ...self::collectViewPaths($value, '' === $prefix ? $name : $prefix.'.'.$name, $depth + 1)];
        }

        return $found;
    }

    /**
     * @param array<mixed> $view
     */
    private static function declaresRootPaths(array $view): bool
    {
        foreach (['templateRootPaths', 'partialRootPaths', 'layoutRootPaths'] as $kind) {
            if (array_key_exists($kind.'.', $view) || array_key_exists($kind, $view)) {
                return true;
            }
        }

        return false;
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
