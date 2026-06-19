<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Command;

use KonradMichalik\Typo3AiMate\Command\Support\LogTrimmer;
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Environment;

use function array_map;
use function array_slice;
use function count;
use function is_string;

/**
 * LogsCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(
    name: 'typo3-ai-mate:logs:search',
    description: 'Search the TYPO3 logs (level/component/request-id/query) and return matching entries as JSON.',
)]
final class LogsCommand extends AbstractJsonCommand
{
    /**
     * PSR-3 severity order: lower number = more severe. A --level filter keeps
     * entries at or above the given severity.
     */
    private const SEVERITY = [
        'EMERGENCY' => 0,
        'ALERT' => 1,
        'CRITICAL' => 2,
        'ERROR' => 3,
        'WARNING' => 4,
        'NOTICE' => 5,
        'INFO' => 6,
        'DEBUG' => 7,
    ];

    /**
     * TYPO3's exception handler writes the full stack trace and JSON context
     * into the log *message* itself (tens of kB per entry), which deduplication
     * alone does not bound. Cap the message body so summaries stay token-cheap.
     */
    private const MESSAGE_LIMIT = 2000;

    /**
     * @return list<array<string, mixed>>
     */
    public function parseFile(string $file): array
    {
        $handle = @fopen($file, 'r');
        if (false === $handle) {
            return [];
        }

        $entries = [];
        $current = null;
        while (($line = fgets($handle)) !== false) {
            $parsed = $this->parseHeaderLine(rtrim($line, "\r\n"));
            if (null !== $parsed) {
                if (null !== $current) {
                    $entries[] = $current;
                }
                $current = $parsed;
            } elseif (null !== $current) {
                // Continuation line (e.g. stack trace) of the current entry.
                $current['trace'] = Cast::string($current['trace'] ?? '').$line;
            }
        }
        if (null !== $current) {
            $entries[] = $current;
        }
        fclose($handle);

        return $entries;
    }

    /**
     * Parse a TYPO3 FileWriter header line:
     *   <time> [<LEVEL>] request="<id>" component="<component>": <message>
     *
     * The default FileWriter timestamp is RFC-2822 ("Mon, 15 Jun 2026 16:16:25
     * +0200") and contains spaces, so time is matched non-greedily up to the
     * level token rather than as a single whitespace-free word.
     *
     * @return array<string, mixed>|null
     */
    public function parseHeaderLine(string $line): ?array
    {
        $pattern = '/^(?<time>.+?) \[(?<level>[A-Z]+)\] request="(?<request>[^"]*)" component="(?<component>[^"]*)": (?<message>.*)$/';
        if (1 !== preg_match($pattern, $line, $matches)) {
            return null;
        }

        return [
            'time' => $matches['time'],
            'level' => $matches['level'],
            'component' => $matches['component'],
            'request_id' => $matches['request'],
            'message' => $matches['message'],
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function entryMatches(array $entry, ?int $minSeverity, ?string $component, ?string $query, ?string $requestId): bool
    {
        if (null !== $minSeverity && (self::SEVERITY[Cast::string($entry['level'] ?? '')] ?? 7) > $minSeverity) {
            return false;
        }
        if (null !== $component && !str_contains(Cast::string($entry['component'] ?? ''), $component)) {
            return false;
        }
        if (null !== $requestId && Cast::string($entry['request_id'] ?? '') !== $requestId) {
            return false;
        }
        if (null !== $query && !str_contains(Cast::string($entry['message'] ?? ''), $query)) {
            return false;
        }

        return true;
    }

    /**
     * Whether the entry's timestamp is at or after the given lower bound. A null
     * bound (no --since) always matches.
     *
     * @param array<string, mixed> $entry
     */
    public function entryReachesSince(array $entry, ?int $sinceTimestamp): bool
    {
        if (null === $sinceTimestamp) {
            return true;
        }
        $time = strtotime(Cast::string($entry['time'] ?? ''));

        return false !== $time && $time >= $sinceTimestamp;
    }

    /**
     * Resolve a --since value to a minimum unix timestamp. Accepts a relative
     * offset (e.g. 30m, 2h, 7d) or any date string parseable by strtotime.
     * Returns null when empty or unparseable (i.e. no lower time bound).
     */
    public function resolveSince(mixed $since): ?int
    {
        if (!is_string($since) || '' === trim($since)) {
            return null;
        }
        $since = trim($since);

        if (1 === preg_match('/^(\d+)\s*([smhd])$/i', $since, $matches)) {
            $unit = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][strtolower($matches[2])] ?? 1;

            return time() - ((int) $matches[1] * $unit);
        }

        $timestamp = strtotime($since);

        return false === $timestamp ? null : $timestamp;
    }

    /**
     * Deduplicate entries by message, count occurrences and keep the most recent
     * timestamp. Entries are expected in chronological (file) order. The full
     * stack trace is intentionally dropped — this is the compact summary view.
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array{message: string, level: string, component: string, count: int, lastSeen: string, exampleRequestId: string}>
     */
    public function aggregate(array $entries): array
    {
        $grouped = [];
        foreach ($entries as $entry) {
            // Group by the capped message: near-identical entries whose only
            // difference is deep in an inlined trace collapse into one.
            $message = LogTrimmer::message(Cast::string($entry['message'] ?? ''), self::MESSAGE_LIMIT);
            if ('' === $message) {
                continue;
            }
            if (!isset($grouped[$message])) {
                $grouped[$message] = [
                    'message' => $message,
                    'level' => Cast::string($entry['level'] ?? ''),
                    'component' => Cast::string($entry['component'] ?? ''),
                    'count' => 0,
                    'lastSeen' => '',
                    'exampleRequestId' => Cast::string($entry['request_id'] ?? ''),
                ];
            }
            ++$grouped[$message]['count'];
            $grouped[$message]['lastSeen'] = Cast::string($entry['time'] ?? '');
        }

        $summaries = array_values($grouped);
        usort($summaries, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $summaries;
    }

    public function resolveMinSeverity(mixed $level): ?int
    {
        if (!is_string($level) || '' === $level) {
            return null;
        }

        return self::SEVERITY[strtoupper(trim($level))] ?? null;
    }

    /**
     * Parse every TYPO3 log file in var/log into a flat, chronological list.
     * Shared by the deprecation and render-page commands, which reuse this
     * command as the log parser rather than re-globbing var/log themselves.
     *
     * @return list<array<string, mixed>>
     */
    public function allEntries(): array
    {
        return $this->parseFiles($this->logFiles());
    }

    protected function configure(): void
    {
        $this
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Minimum severity (emergency|alert|critical|error|warning|notice|info|debug)')
            ->addOption('component', null, InputOption::VALUE_REQUIRED, 'Filter by component/channel substring')
            ->addOption('query', null, InputOption::VALUE_REQUIRED, 'Full-text substring to match in the message')
            ->addOption('request-id', null, InputOption::VALUE_REQUIRED, 'Filter by request id (correlates with profile token)')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only entries at or after this time: relative (e.g. 30m, 2h, 7d) or any parseable date')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'summary (distinct messages with counts, default) or full (individual entries with truncated traces)', 'summary')
            ->addOption('trace-limit', null, InputOption::VALUE_REQUIRED, 'In full format, truncate each stack trace to this many characters (0 = unlimited)', '2000')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of distinct messages (summary) or most recent entries (full)', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entries = $this->filter($this->allEntries(), $input);
        $limit = max(1, Cast::int($input->getOption('limit')));

        if ('full' === $this->resolveFormat($input->getOption('format'))) {
            return $this->emit($output, $this->fullPayload($entries, $limit, max(0, Cast::int($input->getOption('trace-limit')))));
        }

        $summaries = $this->aggregate($entries);

        return $this->emit($output, [
            'mode' => 'summary',
            'totalMatched' => count($entries),
            'distinct' => count($summaries),
            'entries' => array_slice($summaries, 0, $limit),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return array{mode: string, totalMatched: int, entries: list<array<string, mixed>>}
     */
    private function fullPayload(array $entries, int $limit, int $traceLimit): array
    {
        $recent = array_slice($entries, -$limit);

        return [
            'mode' => 'full',
            'totalMatched' => count($entries),
            'entries' => array_map(static fn (array $entry): array => LogTrimmer::entry($entry, self::MESSAGE_LIMIT, $traceLimit), $recent),
        ];
    }

    private function resolveFormat(mixed $format): string
    {
        return 'full' === strtolower(trim(Cast::string($format))) ? 'full' : 'summary';
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
     * @param list<string> $files
     *
     * @return list<array<string, mixed>>
     */
    private function parseFiles(array $files): array
    {
        $entries = [];
        foreach ($files as $file) {
            foreach ($this->parseFile($file) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array<string, mixed>>
     */
    private function filter(array $entries, InputInterface $input): array
    {
        $minSeverity = $this->resolveMinSeverity($input->getOption('level'));
        $component = $this->stringOption($input->getOption('component'));
        $query = $this->stringOption($input->getOption('query'));
        $requestId = $this->stringOption($input->getOption('request-id'));
        $since = $this->resolveSince($input->getOption('since'));

        return array_values(array_filter(
            $entries,
            fn (array $entry): bool => $this->entryMatches($entry, $minSeverity, $component, $query, $requestId)
                && $this->entryReachesSince($entry, $since),
        ));
    }

    private function stringOption(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
