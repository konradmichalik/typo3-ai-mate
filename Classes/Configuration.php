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

namespace KonradMichalik\Typo3AiMate;

use KonradMichalik\Typo3AiMate\Log\DeprecationBacktraceProcessor;
use Psr\Log\LogLevel;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\ArrayUtility;

use function is_array;

/**
 * Configuration.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
class Configuration
{
    final public const EXT_KEY = 'typo3_ai_mate';
    final public const EXT_NAME = 'Typo3AiMate';

    /**
     * Dev-only switch: deprecation backtrace tracking is wired up in the
     * Development context only. Evaluated in ext_localconf.php (and therefore
     * cached), so toggling the context requires a cache flush.
     */
    public static function isDeprecationTrackingActive(): bool
    {
        return Environment::getContext()->isDevelopment();
    }

    /**
     * Capture the caller's backtrace when a deprecation is logged so the
     * typo3-deprecations tool can report a high-confidence origin. Only adds a
     * processor to the (default-disabled) deprecations channel; no effect until
     * deprecation logging is enabled. Uses ??= so an existing processor
     * configuration is never overwritten.
     */
    public static function registerDeprecationBacktraceProcessor(): void
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        if (!is_array($confVars)) {
            return;
        }

        // Array path form keeps the processor FQCN (containing backslashes) and
        // the level intact instead of splitting them on the default delimiter.
        $path = ['LOG', 'TYPO3', 'CMS', 'deprecations', 'processorConfiguration', LogLevel::NOTICE, DeprecationBacktraceProcessor::class];
        if (ArrayUtility::isValidPath($confVars, $path)) {
            return;
        }

        $GLOBALS['TYPO3_CONF_VARS'] = ArrayUtility::setValueByPath($confVars, $path, []);
    }
}
