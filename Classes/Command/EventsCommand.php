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

namespace KonradMichalik\Typo3AiMate\Command;

use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;

use function is_string;

/**
 * EventsCommand.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
#[AsCommand(
    name: 'typo3-ai-mate:events:list',
    description: 'Resolved PSR-14 event listener registry (event => listeners) as JSON.',
)]
final class EventsCommand extends AbstractJsonCommand
{
    public function __construct(private readonly ListenerProvider $listenerProvider)
    {
        parent::__construct();
    }

    /**
     * @return list<array<string, string>>
     */
    public static function mapListeners(mixed $listeners): array
    {
        $mapped = [];
        foreach (Cast::array($listeners) as $identifier => $listener) {
            $listener = Cast::array($listener);
            $method = $listener['method'] ?? null;
            $mapped[] = [
                'identifier' => Cast::string($identifier),
                'service' => Cast::string($listener['service'] ?? ''),
                'method' => null === $method ? '__invoke' : Cast::string($method),
            ];
        }

        return $mapped;
    }

    protected function configure(): void
    {
        $this->addOption('event', null, InputOption::VALUE_REQUIRED, 'Filter by event class substring');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // getAllListenerDefinitions() is marked @internal (debug only) but is the
        // single accessor for the resolved registry; this is a dev-only command.
        $definitions = $this->listenerProvider->getAllListenerDefinitions();
        ksort($definitions);

        $filter = $input->getOption('event');
        $filter = is_string($filter) && '' !== $filter ? $filter : null;

        $events = [];
        foreach ($definitions as $event => $listeners) {
            $eventClass = Cast::string($event);
            if (null !== $filter && !str_contains($eventClass, $filter)) {
                continue;
            }

            $events[] = ['event' => $eventClass, 'listeners' => self::mapListeners($listeners)];
        }

        return $this->emit($output, ['events' => $events]);
    }
}
