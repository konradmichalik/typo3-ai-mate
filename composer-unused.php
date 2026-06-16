<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <km@move-elevator.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

return static function (Configuration $config): Configuration {
    // typo3-request-profiler is a runtime/artifact dependency: the typo3-profiler-*
    // MCP tools read the JSON profiles it writes but reference none of its PHP
    // symbols, so composer-unused cannot detect the link. It is used on purpose.
    return $config
        ->addNamedFilter(NamedFilter::fromString('konradmichalik/typo3-request-profiler'));
};
