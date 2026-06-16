<?php

declare(strict_types=1);

namespace KonradMichalik\ScannerFixture;

use TYPO3\CMS\Core\Database\DatabaseConnection;

/**
 * Deliberately references a core class that was removed in TYPO3 v9. The
 * extension scanner's ClassNameMatcher must flag this usage. Do not "fix" it —
 * this file is a fixture, not production code.
 */
final class LegacyConsumer
{
    public function isLegacyConnection(object $candidate): bool
    {
        return $candidate instanceof DatabaseConnection;
    }

    public function legacyClassName(): string
    {
        return DatabaseConnection::class;
    }
}
