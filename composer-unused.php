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
    // helgesverre/toon is used at runtime by ai-mate's ResponseEncoder to encode tool
    // responses as TOON (token-efficient), but this package never references its symbols
    // directly — so composer-unused cannot detect the link. It is required on purpose.
    return $config
        ->addNamedFilter(NamedFilter::fromString('helgesverre/toon'));
};
