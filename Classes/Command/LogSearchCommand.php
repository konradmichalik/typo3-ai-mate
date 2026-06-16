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
use TYPO3\CMS\Core\Core\Environment;

use function array_slice;
use function is_string;

/**
 * LogSearchCommand.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
#[AsCommand(
    name: 'typo3-ai-mate:logs:search',
    description: 'Search the TYPO3 logs (level/component/request-id/query) and return matching entries as JSON.',
)]
final class LogSearchCommand extends Command
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

    public function resolveMinSeverity(mixed $level): ?int
    {
        if (!is_string($level) || '' === $level) {
            return null;
        }

        return self::SEVERITY[strtoupper(trim($level))] ?? null;
    }

    protected function configure(): void
    {
        $this
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Minimum severity (emergency|alert|critical|error|warning|notice|info|debug)')
            ->addOption('component', null, InputOption::VALUE_REQUIRED, 'Filter by component/channel substring')
            ->addOption('query', null, InputOption::VALUE_REQUIRED, 'Full-text substring to match in the message')
            ->addOption('request-id', null, InputOption::VALUE_REQUIRED, 'Filter by request id (correlates with profile token)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of (most recent) entries', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entries = $this->parseFiles($this->logFiles());
        $entries = $this->filter($entries, $input);

        $limit = max(1, Cast::int($input->getOption('limit')));
        $entries = array_slice($entries, -$limit);

        $json = json_encode($entries, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $output->writeln(false === $json ? '{"error":"Failed to encode JSON."}' : $json);

        return Command::SUCCESS;
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

        return array_values(array_filter(
            $entries,
            fn (array $entry): bool => $this->entryMatches($entry, $minSeverity, $component, $query, $requestId),
        ));
    }

    private function stringOption(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
