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

use Composer\Autoload\ClassLoader;

use function in_array;

/**
 * ClassLoaders.
 *
 * The Composer class loaders to ask about class files. On TYPO3 v13
 * typo3/class-alias-loader replaces Composer's loader in the autoload stack, so
 * ClassLoader::getRegisteredLoaders() comes back empty there. The loader TYPO3
 * booted with is added from the container for that case.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ClassLoaders
{
    /**
     * @return list<ClassLoader>
     */
    public static function with(ClassLoader $booted): array
    {
        $loaders = array_values(ClassLoader::getRegisteredLoaders());
        if (!in_array($booted, $loaders, true)) {
            $loaders[] = $booted;
        }

        return $loaders;
    }
}
