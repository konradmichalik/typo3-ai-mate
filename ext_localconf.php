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

defined('TYPO3') || exit;

(static function (): void {
    // Dev-only: capture the caller's backtrace when a deprecation is logged so
    // the typo3-deprecations tool can report a high-confidence origin. Only adds
    // a processor to the (default-disabled) deprecations channel; no effect until
    // deprecation logging is enabled.
    if (!TYPO3\CMS\Core\Core\Environment::getContext()->isDevelopment()) {
        return;
    }

    $config = &$GLOBALS['TYPO3_CONF_VARS']['LOG']['TYPO3']['CMS']['deprecations']['processorConfiguration'];
    $config[Psr\Log\LogLevel::NOTICE][KonradMichalik\Typo3AiMate\Log\DeprecationBacktraceProcessor::class] ??= [];
})();
