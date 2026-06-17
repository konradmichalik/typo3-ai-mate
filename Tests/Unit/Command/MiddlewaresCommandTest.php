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

use KonradMichalik\Typo3AiMate\Command\MiddlewaresCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MiddlewaresCommandTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final class MiddlewaresCommandTest extends TestCase
{
    #[Test]
    public function mapMiddlewaresUnwrapsTheTargetFromAnArrayDefinition(): void
    {
        $mapped = MiddlewaresCommand::mapMiddlewares([
            'typo3/cms-frontend/timetracker' => ['target' => 'TYPO3\\CMS\\Frontend\\Middleware\\TimeTrackerInitialization'],
        ]);

        self::assertSame(
            [['identifier' => 'typo3/cms-frontend/timetracker', 'target' => 'TYPO3\\CMS\\Frontend\\Middleware\\TimeTrackerInitialization']],
            $mapped,
        );
    }

    #[Test]
    public function mapMiddlewaresKeepsScalarValuesAsTheTarget(): void
    {
        $mapped = MiddlewaresCommand::mapMiddlewares([
            'some/identifier' => 'Some\\Middleware\\ClassName',
        ]);

        self::assertSame(
            [['identifier' => 'some/identifier', 'target' => 'Some\\Middleware\\ClassName']],
            $mapped,
        );
    }

    #[Test]
    public function mapMiddlewaresNullsAnArrayDefinitionWithoutTarget(): void
    {
        $mapped = MiddlewaresCommand::mapMiddlewares([
            'broken/identifier' => ['before' => ['x']],
        ]);

        self::assertNull($mapped[0]['target']);
        self::assertSame('broken/identifier', $mapped[0]['identifier']);
    }

    #[Test]
    public function mapMiddlewaresNullsNonStringIdentifiers(): void
    {
        $mapped = MiddlewaresCommand::mapMiddlewares([
            0 => 'Some\\Middleware',
        ]);

        self::assertNull($mapped[0]['identifier']);
    }
}
