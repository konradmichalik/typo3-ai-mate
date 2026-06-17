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

use KonradMichalik\Typo3AiMate\Command\EventListCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * EventListCommandTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final class EventListCommandTest extends TestCase
{
    #[Test]
    public function mapListenersExtractsIdentifierServiceAndMethod(): void
    {
        $mapped = EventListCommand::mapListeners([
            'my-listener' => ['service' => 'My\\Listener', 'method' => 'onEvent'],
        ]);

        self::assertSame(
            [['identifier' => 'my-listener', 'service' => 'My\\Listener', 'method' => 'onEvent']],
            $mapped,
        );
    }

    #[Test]
    public function mapListenersDefaultsTheMethodToInvokeWhenMissing(): void
    {
        $mapped = EventListCommand::mapListeners([
            'invokable' => ['service' => 'My\\InvokableListener'],
        ]);

        self::assertSame('__invoke', $mapped[0]['method']);
    }

    #[Test]
    public function mapListenersCoercesMissingServiceToAnEmptyString(): void
    {
        $mapped = EventListCommand::mapListeners([
            'partial' => ['method' => 'handle'],
        ]);

        self::assertSame(
            [['identifier' => 'partial', 'service' => '', 'method' => 'handle']],
            $mapped,
        );
    }

    #[Test]
    public function mapListenersToleratesNonArrayInput(): void
    {
        self::assertSame([], EventListCommand::mapListeners(null));
    }
}
