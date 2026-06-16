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

use ComposerUnused\ComposerUnused\Configuration\{Configuration, NamedFilter};

return static function (Configuration $config): Configuration {
    // Both dependencies are used at runtime but expose no PHP symbols this package
    // references, so composer-unused cannot detect the link — they are used on purpose:
    //   * symfony/ai-mate — the host framework; provides the `mate` binary and loads
    //     our MCP tools via the extra.ai-mate declaration.
    //   * typo3-request-profiler — its JSON profiles are read by the typo3-profiler-* tools.
    return $config
        ->addNamedFilter(NamedFilter::fromString('symfony/ai-mate'))
        ->addNamedFilter(NamedFilter::fromString('konradmichalik/typo3-request-profiler'));
};
