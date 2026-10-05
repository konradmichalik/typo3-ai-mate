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

use Composer\InstalledVersions;
use KonradMichalik\Typo3AiMate\Command\Support\ChangelogText;
use KonradMichalik\Typo3AiMate\Mcp\Enum\ChangelogType;
use KonradMichalik\Typo3AiMate\Support\Cast;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use TYPO3\CMS\Core\Information\Typo3Version;

use function array_map;
use function array_slice;
use function count;
use function is_dir;
use function max;
use function min;
use function rtrim;
use function scandir;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function usort;

/**
 * ChangelogSearchCommand.
 *
 * Searches `typo3/cms-core`'s shipped `Documentation/Changelog/` RST files
 * (Breaking/Deprecation/Feature/Important) offline, so a scanner hit
 * ("this API breaks") can be followed by "and here is how to migrate it"
 * without falling back to training-data guesswork. The core ships the
 * entire historical changelog (all majors), so results are scoped to the
 * installed major by default — otherwise "found" would not mean "relevant".
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:changelog:search',
    description: 'Search the installed typo3/cms-core changelog (Breaking/Deprecation/Feature/Important) for migration guidance, as JSON.',
)]
final class ChangelogSearchCommand extends AbstractJsonCommand
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 30;

    /**
     * @param string $changelogPath overrides the changelog root (normally resolved
     *                              from the installed typo3/cms-core package); empty
     *                              string means "resolve it". Test-only seam.
     */
    public function __construct(
        private readonly Typo3Version $typo3Version,
        private readonly string $changelogPath = '',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'Search terms, e.g. a class/method/hook name; every word must match in the filename or content')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Breaking | Deprecation | Feature | Important')
            // Not `version`: the console application reserves that name, and a command
            // carrying it cannot be invoked at all.
            ->addOption('core-version', null, InputOption::VALUE_REQUIRED, 'Version directory prefix, e.g. 13 or 13.4; defaults to the installed major')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum results (capped at 30)', (string) self::DEFAULT_LIMIT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $changelogDirectory = $this->resolveChangelogDirectory();
        if (null === $changelogDirectory) {
            return $this->emit($output, [
                'error' => 'typo3/cms-core does not ship a Documentation/Changelog directory in this installation.',
            ], Command::FAILURE);
        }

        $words = ChangelogText::queryWords(Cast::string($input->getArgument('query')));
        if ([] === $words) {
            return $this->emit($output, ['error' => 'query must not be empty.'], Command::FAILURE);
        }

        $typeOption = Cast::string($input->getOption('type'));
        if (ChangelogType::isUnsupported($typeOption)) {
            return $this->emit($output, ['error' => 'type must be one of Breaking, Deprecation, Feature, or Important.'], Command::FAILURE);
        }
        $type = ChangelogType::tryFrom($typeOption);
        $versionOption = Cast::string($input->getOption('core-version'));
        $versionPrefix = '' !== $versionOption ? $versionOption : (string) $this->typo3Version->getMajorVersion();
        $limit = min(self::MAX_LIMIT, max(1, Cast::int($input->getOption('limit'))));

        $results = $this->search($changelogDirectory, $words, $type, $versionPrefix);
        $total = count($results);

        return $this->emit($output, [
            'version' => $versionPrefix,
            'results' => array_slice($results, 0, $limit),
            'resultCount' => $total,
            '_truncated' => $total > $limit,
        ]);
    }

    /**
     * @param list<string> $words
     *
     * @return list<array{type: string, issue: int, version: string, title: string, excerpt: string, path: string}>
     */
    private function search(string $changelogDirectory, array $words, ?ChangelogType $type, string $versionPrefix): array
    {
        $scored = [];
        foreach ($this->versionDirectories($changelogDirectory, $versionPrefix) as $versionName => $versionPath) {
            foreach ($this->rstFiles($versionPath) as $filename => $absolutePath) {
                $entry = $this->evaluateFile($filename, $absolutePath, $versionName, $words, $type);
                if (null !== $entry) {
                    $scored[] = $entry;
                }
            }
        }

        // Filename matches name the affected API directly and are the stronger
        // signal. Filename is the tiebreaker for equal scores rather than scan
        // order: Finder's iteration order is filesystem-dependent and differs
        // between a developer machine and CI, which would make the result order
        // (and any test asserting it) flaky.
        usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);

        return array_map(static fn (array $entry): array => $entry[2], $scored);
    }

    /**
     * @param list<string> $words
     *
     * @return array{0: int, 1: string, 2: array{type: string, issue: int, version: string, title: string, excerpt: string, path: string}}|null
     */
    private function evaluateFile(string $filename, string $absolutePath, string $versionName, array $words, ?ChangelogType $type): ?array
    {
        $parsed = ChangelogText::parseFilename($filename);
        if (null === $parsed || (null !== $type && $type->value !== $parsed['type'])) {
            return null;
        }

        $content = (string) @file_get_contents($absolutePath);
        if (!ChangelogText::matchesAllWords(strtolower($filename.' '.$content), $words)) {
            return null;
        }

        $filenameScore = 0;
        $lowerFilename = strtolower($filename);
        foreach ($words as $word) {
            if (str_contains($lowerFilename, $word)) {
                ++$filenameScore;
            }
        }

        return [$filenameScore, $filename, [
            'type' => $parsed['type'],
            'issue' => $parsed['issue'],
            'version' => $versionName,
            'title' => ChangelogText::extractTitle($content) ?? $filename,
            'excerpt' => ChangelogText::excerpt($content, $words),
            'path' => 'Documentation/Changelog/'.$versionName.'/'.$filename,
        ]];
    }

    /**
     * @return array<string, string> version directory name => absolute path
     */
    private function versionDirectories(string $changelogDirectory, string $versionPrefix): array
    {
        $directories = [];
        foreach (scandir($changelogDirectory) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry || !is_dir($changelogDirectory.'/'.$entry)) {
                continue;
            }
            if (!str_starts_with($entry, $versionPrefix)) {
                continue;
            }
            $directories[$entry] = $changelogDirectory.'/'.$entry;
        }

        return $directories;
    }

    /**
     * @return array<string, string> filename => absolute path
     */
    private function rstFiles(string $versionPath): array
    {
        $finder = new Finder();
        $finder->files()->in($versionPath)->name('*.rst')->depth(0);

        $files = [];
        foreach ($finder as $file) {
            $files[$file->getFilename()] = $file->getPathname();
        }

        return $files;
    }

    private function resolveChangelogDirectory(): ?string
    {
        $path = '' !== $this->changelogPath ? $this->changelogPath : $this->defaultChangelogDirectory();

        return null !== $path && is_dir($path) ? $path : null;
    }

    private function defaultChangelogDirectory(): ?string
    {
        $corePath = InstalledVersions::getInstallPath('typo3/cms-core');

        return null === $corePath ? null : rtrim($corePath, '/').'/Documentation/Changelog';
    }
}
