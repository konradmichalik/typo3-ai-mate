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
 * SysLogType.
 *
 * Names for the numeric `sys_log.type` values of `TYPO3\CMS\Core\SysLog\Type`.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
enum SysLogType: string
{
    case Db = 'db';
    case File = 'file';
    case Cache = 'cache';
    case Extension = 'extension';
    case Error = 'error';
    case Site = 'site';
    case Setting = 'setting';
    case Login = 'login';
}
