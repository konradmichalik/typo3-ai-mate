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

use KonradMichalik\Typo3AiMate\Command\Support\LogAggregator;
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Environment;

use function is_string;
use function strlen;

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
     * Hard memory guard while accumulating a single entry's trace during parsing.
     * A runaway (or maliciously huge) stack trace would otherwise be read into
     * memory in full before the display trace-limit ever applies. 64 KiB keeps
     * far more than the default output trace-limit while bounding worst-case RAM.
     */
    private const TRACE_ACCUMULATION_LIMIT = 65536;

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
                $current = $this->appendTrace($current, $line);
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
        return (new LogAggregator(self::MESSAGE_LIMIT))->summaries($entries);
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
        $since = $this->resolveSince($input->getOption('since'));
        $filters = [
            'minSeverity' => $this->resolveMinSeverity($input->getOption('level')),
            'component' => $this->stringOption($input->getOption('component')),
            'query' => $this->stringOption($input->getOption('query')),
            'requestId' => $this->stringOption($input->getOption('request-id')),
            'since' => $since,
        ];
        $limit = max(1, Cast::int($input->getOption('limit')));
        $entries = $this->matchingEntries($this->logFiles($since), $filters);
        $aggregator = new LogAggregator(self::MESSAGE_LIMIT);

        $isFull = 'full' === strtolower(trim(Cast::string($input->getOption('format'))));
        $payload = $isFull
            ? $aggregator->recent($entries, $limit, max(0, Cast::int($input->getOption('trace-limit'))))
            : $aggregator->summary($entries, $limit);

        return $this->emit($output, $payload);
    }

    /**
     * Append a continuation line to the entry's trace, but stop once the memory
     * guard is reached so a runaway trace cannot be read into memory unbounded.
     *
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function appendTrace(array $entry, string $line): array
    {
        $trace = Cast::string($entry['trace'] ?? '');
        if (strlen($trace) < self::TRACE_ACCUMULATION_LIMIT) {
            $entry['trace'] = $trace.$line;
        }

        return $entry;
    }

    /**
     * Yield every entry across the given files that passes all filters. Files are
     * read one at a time so only a single file's entries are held at once.
     *
     * @param list<string>                                                                                                      $files
     * @param array{minSeverity: int|null, component: string|null, query: string|null, requestId: string|null, since: int|null} $filters
     *
     * @return iterable<array<string, mixed>>
     */
    private function matchingEntries(array $files, array $filters): iterable
    {
        foreach ($files as $file) {
            yield from $this->matchingEntriesInFile($file, $filters);
        }
    }

    /**
     * @param array{minSeverity: int|null, component: string|null, query: string|null, requestId: string|null, since: int|null} $filters
     *
     * @return iterable<array<string, mixed>>
     */
    private function matchingEntriesInFile(string $file, array $filters): iterable
    {
        foreach ($this->parseFile($file) as $entry) {
            if ($this->entryPasses($entry, $filters)) {
                yield $entry;
            }
        }
    }

    /**
     * @param array<string, mixed>                                                                                              $entry
     * @param array{minSeverity: int|null, component: string|null, query: string|null, requestId: string|null, since: int|null} $filters
     */
    private function entryPasses(array $entry, array $filters): bool
    {
        return $this->entryMatches($entry, $filters['minSeverity'], $filters['component'], $filters['query'], $filters['requestId'])
            && $this->entryReachesSince($entry, $filters['since']);
    }

    /**
     * The log files to read. When a --since lower bound is given, files whose last
     * modification predates it cannot contain newer entries and are skipped.
     *
     * @return list<string>
     */
    private function logFiles(?int $since = null): array
    {
        $files = glob(Environment::getVarPath().'/log/typo3_*.log') ?: [];
        sort($files);
        if (null !== $since) {
            $files = array_values(array_filter($files, static fn (string $file): bool => (int) @filemtime($file) >= $since));
        }

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

    private function stringOption(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
