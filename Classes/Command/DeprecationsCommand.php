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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Environment;

/**
 * DeprecationsCommand.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:upgrade:deprecations',
    description: 'Runtime deprecation notices, deduplicated and grouped by message with counts, as JSON.',
)]
final class DeprecationsCommand extends AbstractJsonCommand
{
    private const CHANNEL = 'TYPO3.CMS.deprecations';

    private readonly LogSearchCommand $logSearch;

    public function __construct()
    {
        parent::__construct();
        $this->logSearch = new LogSearchCommand();
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function isDeprecationEntry(array $entry): bool
    {
        return str_contains(Cast::string($entry['component'] ?? ''), self::CHANNEL);
    }

    /**
     * Deduplicate deprecation log entries by message, count occurrences and
     * keep the most recent timestamp. Entries are expected in chronological
     * (file) order, so the last seen time wins.
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array{message: string, component: string, count: int, lastSeen: string, exampleRequestId: string}>
     */
    public function aggregate(array $entries): array
    {
        $grouped = [];
        foreach ($entries as $entry) {
            $message = Cast::string($entry['message'] ?? '');
            if ('' === $message) {
                continue;
            }
            if (!isset($grouped[$message])) {
                $grouped[$message] = [
                    'message' => $message,
                    'component' => Cast::string($entry['component'] ?? ''),
                    'count' => 0,
                    'lastSeen' => '',
                    'exampleRequestId' => Cast::string($entry['request_id'] ?? ''),
                ];
            }
            ++$grouped[$message]['count'];
            $grouped[$message]['lastSeen'] = Cast::string($entry['time'] ?? '');
        }

        $deprecations = array_values($grouped);
        usort($deprecations, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $deprecations;
    }

    /**
     * Whether at least one writer of the deprecations channel is active.
     *
     * @param array<mixed> $writerConfiguration the [LOG][TYPO3][CMS][deprecations][writerConfiguration] subtree
     */
    public function isDeprecationLoggingEnabled(array $writerConfiguration): bool
    {
        foreach ($writerConfiguration as $writers) {
            foreach (Cast::array($writers) as $writer) {
                if (true !== (Cast::array($writer)['disabled'] ?? false)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entries = [];
        foreach ($this->logFiles() as $file) {
            foreach ($this->logSearch->parseFile($file) as $entry) {
                if ($this->isDeprecationEntry($entry)) {
                    $entries[] = $entry;
                }
            }
        }

        return $this->emit($output, [
            'loggingEnabled' => $this->isDeprecationLoggingEnabled($this->writerConfiguration()),
            'deprecations' => $this->aggregate($entries),
        ]);
    }

    /**
     * @return list<string>
     */
    private function logFiles(): array
    {
        $files = glob(Environment::getVarPath().'/log/typo3_*.log') ?: [];
        sort($files);

        return $files;
    }

    /**
     * @return array<mixed>
     */
    private function writerConfiguration(): array
    {
        $confVars = Cast::array($GLOBALS['TYPO3_CONF_VARS'] ?? []);
        $log = Cast::array($confVars['LOG'] ?? []);
        $typo3 = Cast::array($log['TYPO3'] ?? []);
        $cms = Cast::array($typo3['CMS'] ?? []);
        $deprecations = Cast::array($cms['deprecations'] ?? []);

        return Cast::array($deprecations['writerConfiguration'] ?? []);
    }
}
