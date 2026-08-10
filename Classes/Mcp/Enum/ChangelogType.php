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

namespace KonradMichalik\Typo3AiMate\Mcp\Enum;

/**
 * ChangelogType.
 *
 * Matches the `<Type>-<issue>-<Title>.rst` filename prefix used throughout
 * `typo3/cms-core`'s `Documentation/Changelog/`.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
enum ChangelogType: string
{
    case Breaking = 'Breaking';
    case Deprecation = 'Deprecation';
    case Feature = 'Feature';
    case Important = 'Important';
}
