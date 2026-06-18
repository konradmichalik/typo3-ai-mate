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

use KonradMichalik\Typo3AiMate\Command\Support\DeprecationOriginResolver;
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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

    private readonly LogsCommand $logSearch;

    public function __construct(private readonly PackageManager $packageManager)
    {
        parent::__construct();
        $this->logSearch = new LogsCommand();
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
        $entries = array_values(array_filter($this->logSearch->allEntries(), $this->isDeprecationEntry(...)));

        $deprecations = $this->aggregate($entries);
        if ([] !== $deprecations) {
            $deprecations = $this->attachOrigins($deprecations, $entries);
        }

        return $this->emit($output, [
            'loggingEnabled' => $this->isDeprecationLoggingEnabled($this->writerConfiguration()),
            'deprecations' => $deprecations,
        ]);
    }

    /**
     * Point each deprecation back at the likely caller in own code (trace frame
     * if present, otherwise a static reverse search for the deprecated symbol).
     *
     * @param list<array<string, mixed>> $deprecations
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array<string, mixed>>
     */
    private function attachOrigins(array $deprecations, array $entries): array
    {
        $resolver = new DeprecationOriginResolver($this->buildOwnFileIndex());
        $traces = $this->tracesByMessage($entries);

        return array_map(
            static fn (array $deprecation): array => [
                ...$deprecation,
                'origins' => $resolver->resolve(
                    Cast::string($deprecation['message'] ?? ''),
                    $traces[Cast::string($deprecation['message'] ?? '')] ?? null,
                ),
            ],
            $deprecations,
        );
    }

    /**
     * First backtrace seen per message (most entries carry none, but a
     * configured backtrace yields a high-confidence origin).
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return array<string, string>
     */
    private function tracesByMessage(array $entries): array
    {
        $traces = [];
        foreach ($entries as $entry) {
            $message = Cast::string($entry['message'] ?? '');
            if ('' !== $message && !isset($traces[$message]) && isset($entry['trace'])) {
                $traces[$message] = Cast::string($entry['trace']);
            }
        }

        return $traces;
    }

    /**
     * Read own (non-vendor) extension PHP/Fluid files into a flat index the
     * resolver searches. Third-party packages under vendor/ are skipped — the
     * point is to find the caller in code the user actually maintains.
     *
     * @return list<array{path: string, label: string, content: string}>
     */
    private function buildOwnFileIndex(): array
    {
        $files = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            if ('typo3-cms-extension' !== $package->getValueFromComposerManifest('type')) {
                continue;
            }
            $basePath = GeneralUtility::fixWindowsFilePath($package->getPackagePath());
            if (str_contains($basePath, '/vendor/')) {
                continue;
            }
            foreach ($this->readPackageFiles($basePath, $package->getPackageKey()) as $file) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @return list<array{path: string, label: string, content: string}>
     */
    private function readPackageFiles(string $basePath, string $packageKey): array
    {
        $finder = new Finder();
        $finder->files()->ignoreUnreadableDirs()->in($basePath)
            ->exclude(['vendor', 'node_modules', '.git', '.Build'])
            ->name(['*.php', '*.html'])
            ->size('< 512K')
            ->sortByName();

        $files = [];
        foreach ($finder as $file) {
            $content = file_get_contents($file->getPathname());
            if (false === $content) {
                continue;
            }
            $files[] = [
                'path' => GeneralUtility::fixWindowsFilePath($file->getPathname()),
                'label' => $packageKey.'/'.GeneralUtility::fixWindowsFilePath($file->getRelativePathname()),
                'content' => $content,
            ];
        }

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
