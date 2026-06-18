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
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;

use function sprintf;

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

    #[Test]
    public function executeListsEventsSortedWithMappedListeners(): void
    {
        $provider = $this->createMock(ListenerProvider::class);
        $provider->method('getAllListenerDefinitions')->willReturn([
            'B\\Event' => ['l2' => ['service' => 'S2', 'method' => 'run']],
            'A\\Event' => ['l1' => ['service' => 'S1']],
        ]);

        $tester = new CommandTester(new EventsCommand($provider));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        $events = $result['events'];
        self::assertIsArray($events);
        self::assertSame(['A\\Event', 'B\\Event'], array_column($events, 'event'));
        self::assertSame(2, $result['eventCount']);
        self::assertFalse($result['_truncated']);

        $firstEvent = $events[0];
        self::assertIsArray($firstEvent);
        $listeners = $firstEvent['listeners'];
        self::assertIsArray($listeners);
        $firstListener = $listeners[0];
        self::assertIsArray($firstListener);
        self::assertSame('__invoke', $firstListener['method']);
    }

    #[Test]
    public function executeFiltersEventsByClassSubstring(): void
    {
        $provider = $this->createMock(ListenerProvider::class);
        $provider->method('getAllListenerDefinitions')->willReturn([
            'My\\Cache\\Event' => [],
            'My\\Other\\Event' => [],
        ]);

        $tester = new CommandTester(new EventsCommand($provider));
        $tester->execute(['--event' => 'Cache']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        $events = $result['events'];
        self::assertIsArray($events);
        self::assertSame(['My\\Cache\\Event'], array_column($events, 'event'));
    }

    #[Test]
    public function executeTruncatesAtTheEventCap(): void
    {
        $definitions = [];
        for ($i = 0; $i < 150; ++$i) {
            $definitions[sprintf('Event\\%03d', $i)] = [];
        }
        $provider = $this->createMock(ListenerProvider::class);
        $provider->method('getAllListenerDefinitions')->willReturn($definitions);

        $tester = new CommandTester(new EventsCommand($provider));
        $tester->execute([]);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        $events = $result['events'];
        self::assertIsArray($events);
        self::assertCount(100, $events);
        self::assertSame(150, $result['eventCount']);
        self::assertTrue($result['_truncated']);
    }
}
