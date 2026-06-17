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

use KonradMichalik\Typo3AiMate\Command\EventsCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * EventsCommandTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final class EventsCommandTest extends TestCase
{
    #[Test]
    public function mapListenersExtractsIdentifierServiceAndMethod(): void
    {
        $mapped = EventsCommand::mapListeners([
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
        $mapped = EventsCommand::mapListeners([
            'invokable' => ['service' => 'My\\InvokableListener'],
        ]);

        self::assertSame('__invoke', $mapped[0]['method']);
    }

    #[Test]
    public function mapListenersCoercesMissingServiceToAnEmptyString(): void
    {
        $mapped = EventsCommand::mapListeners([
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
        self::assertSame([], EventsCommand::mapListeners(null));
    }
}
