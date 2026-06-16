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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command;

use KonradMichalik\Typo3AiMate\Command\DeprecationsCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DeprecationsCommandTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final class DeprecationsCommandTest extends TestCase
{
    private DeprecationsCommand $command;

    protected function setUp(): void
    {
        $this->command = new DeprecationsCommand();
    }

    #[Test]
    public function isDeprecationEntryMatchesOnlyTheDeprecationsChannel(): void
    {
        self::assertTrue($this->command->isDeprecationEntry(['component' => 'TYPO3.CMS.deprecations']));
        self::assertFalse($this->command->isDeprecationEntry(['component' => 'TYPO3.CMS.Core.Error']));
        self::assertFalse($this->command->isDeprecationEntry([]));
    }

    #[Test]
    public function aggregateDeduplicatesByMessageCountsAndKeepsLastSeen(): void
    {
        $deprecations = $this->command->aggregate([
            ['message' => 'Foo is deprecated', 'component' => 'TYPO3.CMS.deprecations', 'time' => 'T1', 'request_id' => 'r1'],
            ['message' => 'Bar is deprecated', 'component' => 'TYPO3.CMS.deprecations', 'time' => 'T2', 'request_id' => 'r2'],
            ['message' => 'Foo is deprecated', 'component' => 'TYPO3.CMS.deprecations', 'time' => 'T3', 'request_id' => 'r3'],
        ]);

        // Most frequent first.
        self::assertSame('Foo is deprecated', $deprecations[0]['message']);
        self::assertSame(2, $deprecations[0]['count']);
        self::assertSame('T3', $deprecations[0]['lastSeen']);
        self::assertSame('r1', $deprecations[0]['exampleRequestId']);

        self::assertSame('Bar is deprecated', $deprecations[1]['message']);
        self::assertSame(1, $deprecations[1]['count']);
    }

    #[Test]
    public function isDeprecationLoggingEnabledDetectsTheDisabledDefault(): void
    {
        $defaultDisabled = [
            'NOTICE' => [
                'TYPO3\\CMS\\Core\\Log\\Writer\\FileWriter' => [
                    'logFileInfix' => 'deprecations',
                    'disabled' => true,
                ],
            ],
        ];
        self::assertFalse($this->command->isDeprecationLoggingEnabled($defaultDisabled));

        $enabled = [
            'NOTICE' => [
                'TYPO3\\CMS\\Core\\Log\\Writer\\FileWriter' => [
                    'logFileInfix' => 'deprecations',
                ],
            ],
        ];
        self::assertTrue($this->command->isDeprecationLoggingEnabled($enabled));

        self::assertFalse($this->command->isDeprecationLoggingEnabled([]));
    }
}
