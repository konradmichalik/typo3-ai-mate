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

use Composer\Autoload\ClassLoader;
use FilesystemIterator;
use KonradMichalik\Typo3AiMate\Support\Cast;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Throwable;
use TYPO3Fluid\Fluid\Core\ViewHelper\{ViewHelperInterface, ViewHelperResolverDelegateInterface};

use function strlen;

/**
 * ViewHelperCatalog.
 *
 * Fluid resolves a name by building a class name from it and keeps no listing
 * of its own, so the names of a namespace are derived from the classes the
 * autoloader can find below each of its PHP namespaces.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ViewHelperCatalog
{
    /**
     * @param mixed             $phpNamespaces the PHP namespaces one prefix maps to
     * @param list<ClassLoader> $loaders       the class loaders to enumerate classes from
     *
     * @return array{names: list<string>, unlisted: list<string>} unlisted are resolver delegates, which cannot enumerate their ViewHelpers
     */
    public static function names(mixed $phpNamespaces, array $loaders): array
    {
        $names = [];
        $unlisted = [];
        foreach (Cast::array($phpNamespaces) as $phpNamespace) {
            $phpNamespace = rtrim(Cast::string($phpNamespace), '\\');
            if (is_a($phpNamespace, ViewHelperResolverDelegateInterface::class, true)) {
                $unlisted[] = $phpNamespace;
            } elseif ('' !== $phpNamespace) {
                $names = [...$names, ...self::namesIn($phpNamespace, $loaders)];
            }
        }
        $names = array_values(array_unique($names));
        sort($names);

        return ['names' => $names, 'unlisted' => $unlisted];
    }

    /**
     * @phpstan-assert-if-true class-string<ViewHelperInterface> $className
     */
    public static function isViewHelper(string $className): bool
    {
        // Loading a class whose parent belongs to an uninstalled package throws.
        try {
            return class_exists($className)
                && is_subclass_of($className, ViewHelperInterface::class)
                && !(new ReflectionClass($className))->isAbstract();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param list<ClassLoader> $loaders
     *
     * @return list<string>
     */
    private static function namesIn(string $phpNamespace, array $loaders): array
    {
        $names = [];
        foreach (self::classesBelow($phpNamespace.'\\', $loaders) as $className) {
            $name = ViewHelperLookup::nameFromClass($phpNamespace, $className);
            if (null !== $name && self::isViewHelper($className)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param list<ClassLoader> $loaders
     *
     * @return list<string>
     */
    private static function classesBelow(string $prefix, array $loaders): array
    {
        $classes = [];
        foreach ($loaders as $loader) {
            $classes = [
                ...$classes,
                ...array_filter(array_keys($loader->getClassMap()), static fn (string $className): bool => str_starts_with($className, $prefix)),
            ];
            foreach (self::psr4Directories($loader, $prefix) as $directory) {
                $classes = [...$classes, ...self::classesInDirectory($directory, $prefix)];
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * @return list<string>
     */
    private static function psr4Directories(ClassLoader $loader, string $prefix): array
    {
        $directories = [];
        foreach ($loader->getPrefixesPsr4() as $psr4Prefix => $paths) {
            if (str_starts_with($prefix, $psr4Prefix)) {
                $subPath = str_replace('\\', '/', substr($prefix, strlen($psr4Prefix)));
                $directories = [...$directories, ...array_map(static fn (string $path): string => rtrim($path.'/'.$subPath, '/'), $paths)];
            }
        }

        return $directories;
    }

    /**
     * @return list<string>
     */
    private static function classesInDirectory(string $directory, string $prefix): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), 'ViewHelper.php')) {
                $relative = substr($file->getPathname(), strlen($directory) + 1, -4);
                $classes[] = $prefix.str_replace('/', '\\', $relative);
            }
        }

        return $classes;
    }
}
