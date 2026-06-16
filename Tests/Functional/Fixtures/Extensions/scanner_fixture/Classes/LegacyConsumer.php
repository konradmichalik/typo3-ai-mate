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

namespace KonradMichalik\ScannerFixture;

use TYPO3\CMS\Core\Database\DatabaseConnection;

/**
 * LegacyConsumer.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
final class LegacyConsumer
{
    public function isLegacyConnection(object $candidate): bool
    {
        // Deliberately references a core class removed in TYPO3 v9 so the
        // extension scanner's ClassNameMatcher flags it. This is a test
        // fixture — do not "fix" it.
        return $candidate instanceof DatabaseConnection;
    }

    public function legacyClassName(): string
    {
        return DatabaseConnection::class;
    }
}
