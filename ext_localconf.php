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

use KonradMichalik\Typo3AiMate\Configuration;

defined('TYPO3') || exit;

// Dev-only: capture the caller's backtrace when a deprecation is logged so the
// typo3-deprecations tool can report a high-confidence origin.
if (Configuration::isDeprecationTrackingActive()) {
    Configuration::registerDeprecationBacktraceProcessor();
}
