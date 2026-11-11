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

use Closure;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function strlen;

/**
 * ClassOwnerResolver.
 *
 * Attributes a class to the active package it belongs to without loading it.
 * The file the autoloader would include decides first: a class file inside a
 * package path belongs to that package. A class the autoloader cannot find
 * (a typo in the registration, a package that is not autoloaded) falls back to
 * the PSR-4 namespaces the packages declare in their composer.json. Nested
 * packages are handled by preferring the longest matching path or namespace.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class ClassOwnerResolver
{
    /**
     * @var list<array{extension: string, package: string, path: string, namespaces: list<string>}>
     */
    private array $packages;

    /**
     * @param list<array{extension: string, package: string, path: string, namespaces: list<string>}> $packages
     * @param Closure(string): ?string                                                                $findFile the file the autoloader would include for a class, null if none
     */
    public function __construct(array $packages, private Closure $findFile)
    {
        $this->packages = array_map(
            static fn (array $package): array => [...$package, 'path' => self::canonical($package['path']).'/'],
            $packages,
        );
    }

    /**
     * @return array{extension: string|null, package: string|null, determinedBy: 'classFile'|'namespace'|null}
     */
    public function resolve(string $class): array
    {
        $class = ltrim($class, '\\');

        $file = ($this->findFile)($class);
        $owner = null !== $file ? $this->byFile(self::canonical($file)) : null;
        if (null !== $owner) {
            return ['extension' => $owner['extension'], 'package' => $owner['package'], 'determinedBy' => 'classFile'];
        }

        $owner = $this->byNamespace($class);
        if (null !== $owner) {
            return ['extension' => $owner['extension'], 'package' => $owner['package'], 'determinedBy' => 'namespace'];
        }

        return ['extension' => null, 'package' => null, 'determinedBy' => null];
    }

    /**
     * @return array{extension: string, package: string, path: string, namespaces: list<string>}|null
     */
    private function byFile(string $file): ?array
    {
        $best = null;
        foreach ($this->packages as $package) {
            if (str_starts_with($file, $package['path']) && strlen($package['path']) > strlen($best['path'] ?? '')) {
                $best = $package;
            }
        }

        return $best;
    }

    /**
     * @return array{extension: string, package: string, path: string, namespaces: list<string>}|null
     */
    private function byNamespace(string $class): ?array
    {
        $best = null;
        $bestLength = 0;
        foreach ($this->packages as $package) {
            foreach ($package['namespaces'] as $namespace) {
                if (str_starts_with($class, $namespace) && strlen($namespace) > $bestLength) {
                    $best = $package;
                    $bestLength = strlen($namespace);
                }
            }
        }

        return $best;
    }

    private static function canonical(string $path): string
    {
        $real = realpath($path);

        return rtrim(GeneralUtility::fixWindowsFilePath(false !== $real ? $real : $path), '/');
    }
}
