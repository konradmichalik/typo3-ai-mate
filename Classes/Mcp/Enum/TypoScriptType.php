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

namespace KonradMichalik\Typo3AiMate\Mcp\Enum;

/**
 * TypoScriptType.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
enum TypoScriptType: string
{
    case Setup = 'setup';
    case Constants = 'constants';
}
