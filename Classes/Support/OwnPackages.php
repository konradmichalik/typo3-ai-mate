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

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * OwnPackages.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class OwnPackages
{
    /**
     * @return 'own'|'thirdParty'
     */
    public static function origin(string $packagePath): string
    {
        return self::isOwn($packagePath) ? 'own' : 'thirdParty';
    }

    public static function isOwn(string $packagePath): bool
    {
        // Composer path-repository packages (packages/*) are symlinked into
        // vendor/, so the reported path sits under vendor/ even though the real
        // code does not. realpath() resolves the symlink back to its true
        // location, so a plain "/vendor/" substring check would misjudge them.
        $resolved = self::canonical($packagePath);
        $vendorDir = self::canonical(Environment::getProjectPath()).'/vendor/';

        return !str_starts_with(rtrim($resolved, '/').'/', $vendorDir);
    }

    private static function canonical(string $path): string
    {
        $real = realpath($path);

        return GeneralUtility::fixWindowsFilePath(false !== $real ? $real : $path);
    }
}
