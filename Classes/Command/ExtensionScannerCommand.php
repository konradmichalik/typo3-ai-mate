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

use KonradMichalik\Typo3AiMate\Command\Support\ScanResultFormatter;
use KonradMichalik\Typo3AiMate\Support\Cast;
use PhpParser\{NodeTraverser, NodeVisitor, ParserFactory, PhpVersion};
use PhpParser\NodeVisitor\NameResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Throwable;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Install\ExtensionScanner\CodeScannerInterface;
use TYPO3\CMS\Install\ExtensionScanner\Php\{CodeStatistics, GeneratorClassesResolver, MatcherFactory};

use function count;
use function is_array;
use function sprintf;

/**
 * ExtensionScannerCommand.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
#[AsCommand(
    name: 'typo3-ai-mate:upgrade:scan',
    description: 'Static scan of an extension against the core breaking/deprecation matchers as JSON.',
)]
final class ExtensionScannerCommand extends AbstractJsonCommand
{
    private const MATCHER_NAMESPACE = 'TYPO3\\CMS\\Install\\ExtensionScanner\\Php\\Matcher\\';
    private const CONFIG_DIR = 'EXT:install/Configuration/ExtensionScanner/Php';

    private readonly ScanResultFormatter $formatter;

    public function __construct(private readonly PackageManager $packageManager)
    {
        parent::__construct();
        $this->formatter = new ScanResultFormatter();
    }

    /**
     * Derive the MatcherFactory configuration from the core matcher config
     * directory. The configuration file basename equals the matcher class
     * short name (1:1 convention), so we read the directory at runtime instead
     * of copying the version-specific mapping list out of the core.
     *
     * @param list<string> $configFileBasenames
     *
     * @return list<array{class: class-string, configurationFile: string}>
     */
    public function buildMatcherConfigurations(array $configFileBasenames): array
    {
        $configurations = [];
        foreach ($configFileBasenames as $basename) {
            if (!str_ends_with($basename, '.php')) {
                continue;
            }
            /** @var class-string $class */
            $class = self::MATCHER_NAMESPACE.substr($basename, 0, -4);
            if (!class_exists($class) || !is_subclass_of($class, CodeScannerInterface::class)) {
                continue;
            }
            $configurations[] = [
                'class' => $class,
                'configurationFile' => self::CONFIG_DIR.'/'.$basename,
            ];
        }

        return $configurations;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('extension', InputArgument::OPTIONAL, 'Extension key to scan, e.g. my_ext. Omit to scan all non-core extensions.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'summary (matches grouped by message with strong/weak counts, default) or full (individual matches with line content)', 'summary')
            ->addOption('own-code', null, InputOption::VALUE_NONE, 'When scanning all extensions, restrict to own extensions (outside vendor/) and skip third-party packages');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $matcherConfigurations = $this->buildMatcherConfigurations($this->configFileBasenames());
        $format = $this->resolveFormat($input->getOption('format'));
        $extension = Cast::string($input->getArgument('extension'));

        if ('' !== $extension) {
            $result = $this->scanExtension($extension, $matcherConfigurations);
            if (isset($result['error'])) {
                return $this->emit($output, $result, Command::FAILURE);
            }

            return $this->emit($output, $this->formatter->format($result, $format));
        }

        return $this->emit($output, $this->scanAll($matcherConfigurations, $format, (bool) $input->getOption('own-code')));
    }

    /**
     * Scan every active non-core extension (optionally own code only) and shape
     * the combined result for the requested format.
     *
     * @param list<array{class: class-string, configurationFile: string}> $matcherConfigurations
     *
     * @return array<string, mixed>
     */
    private function scanAll(array $matcherConfigurations, string $format, bool $ownOnly): array
    {
        $results = [];
        foreach ($this->scannableExtensionKeys($ownOnly) as $key) {
            $results[] = $this->scanExtension($key, $matcherConfigurations);
        }

        if ('full' === $format) {
            return ['mode' => 'full', 'extensions' => array_map($this->formatter->full(...), $results)];
        }

        // Summary: drop clean extensions, keep only those with findings, and add a rollup.
        $withMatches = array_values(array_filter($results, $this->formatter->hasMatches(...)));

        return [
            'mode' => 'summary',
            'totals' => $this->formatter->rollup($results),
            'extensions' => array_map($this->formatter->summary(...), $withMatches),
        ];
    }

    /**
     * @param list<array{class: class-string, configurationFile: string}> $matcherConfigurations
     *
     * @return array<string, mixed>
     */
    private function scanExtension(string $extension, array $matcherConfigurations): array
    {
        try {
            $basePath = $this->packageManager->getPackage($extension)->getPackagePath();
        } catch (Throwable) {
            return ['extension' => $extension, 'error' => sprintf('Unknown or inactive extension "%s".', $extension)];
        }
        if (!is_dir($basePath)) {
            return ['extension' => $extension, 'error' => sprintf('Extension path "%s" is not a directory.', $basePath)];
        }

        $matches = [];
        $effectiveCodeLines = 0;
        $ignoredLines = 0;
        $filesScanned = 0;
        $filesSkipped = 0;

        foreach ($this->phpFiles($basePath) as $relativeName => $absolutePath) {
            $result = $this->scanFile($absolutePath, $relativeName, $matcherConfigurations);
            if (null === $result) {
                ++$filesSkipped;
                continue;
            }
            ++$filesScanned;
            $effectiveCodeLines += $result['effectiveCodeLines'];
            $ignoredLines += $result['ignoredLines'];
            foreach ($result['matches'] as $match) {
                $matches[] = $match;
            }
        }

        $strong = count(array_filter($matches, static fn (array $m): bool => 'strong' === ($m['indicator'] ?? '')));

        return [
            'extension' => $extension,
            'origin' => $this->originForPath($basePath),
            'statistics' => [
                'effectiveCodeLines' => $effectiveCodeLines,
                'ignoredLines' => $ignoredLines,
                'filesScanned' => $filesScanned,
                'filesSkipped' => $filesSkipped,
                'matchCount' => count($matches),
                'strong' => $strong,
                'weak' => count($matches) - $strong,
            ],
            'matches' => $matches,
        ];
    }

    /**
     * Whether the package lives in vendor/ (third-party) or outside it (own
     * code, typically a path-repository package). The scanner's most useful
     * filter for an upgrade is "show me only the code I have to fix myself".
     */
    private function originForPath(string $basePath): string
    {
        return str_contains(GeneralUtility::fixWindowsFilePath($basePath), '/vendor/') ? 'thirdParty' : 'own';
    }

    private function resolveFormat(mixed $format): string
    {
        return 'full' === strtolower(trim(Cast::string($format))) ? 'full' : 'summary';
    }

    /**
     * Active non-core extensions (composer type "typo3-cms-extension"): the user's
     * own plus third-party extensions, excluding the core system extensions
     * (type "typo3-cms-framework") which are maintained upstream.
     *
     * @return list<string>
     */
    private function scannableExtensionKeys(bool $ownOnly): array
    {
        $keys = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            if ('typo3-cms-extension' !== $package->getValueFromComposerManifest('type')) {
                continue;
            }
            if ($ownOnly && 'own' !== $this->originForPath($package->getPackagePath())) {
                continue;
            }
            $keys[] = $package->getPackageKey();
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function configFileBasenames(): array
    {
        $absoluteConfigDir = GeneralUtility::getFileAbsFileName(self::CONFIG_DIR);
        $files = glob($absoluteConfigDir.'/*.php') ?: [];

        return array_map(basename(...), $files);
    }

    /**
     * @return array<string, string> relative file name => absolute path
     */
    private function phpFiles(string $basePath): array
    {
        $finder = new Finder();
        $finder->files()->ignoreUnreadableDirs()->in($basePath)->name('*.php')->sortByName();

        $files = [];
        foreach ($finder as $file) {
            $files[GeneralUtility::fixWindowsFilePath($file->getRelativePathname())] = $file->getPathname();
        }

        return $files;
    }

    /**
     * @param list<array{class: class-string, configurationFile: string}> $matcherConfigurations
     *
     * @return array{matches: list<array<string, mixed>>, effectiveCodeLines: int, ignoredLines: int}|null
     */
    private function scanFile(string $absolutePath, string $relativeName, array $matcherConfigurations): ?array
    {
        $code = file_get_contents($absolutePath);
        if (false === $code) {
            return null;
        }

        // A single matcher can throw on an edge-case AST node (the backend
        // module isolates this per file via separate AJAX calls). Wrap the whole
        // pipeline so one unparseable/problematic file is skipped rather than
        // aborting the entire scan.
        try {
            $statements = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 2))->parse($code);
            if (null === $statements) {
                return null;
            }

            // First pass: resolve `use` aliases to fully qualified names so the
            // matchers (and GeneratorClassesResolver) see reliable class names.
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $statements = $traverser->traverse($statements);

            // Second pass: run the resolvers, the statistics collector and all matchers.
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new GeneratorClassesResolver());
            $statistics = new CodeStatistics();
            $traverser->addVisitor($statistics);

            $matchers = (new MatcherFactory())->createAll($matcherConfigurations);
            foreach ($matchers as $matcher) {
                if ($matcher instanceof NodeVisitor) {
                    $traverser->addVisitor($matcher);
                }
            }
            $traverser->traverse($statements);

            $matches = [];
            foreach ($matchers as $matcher) {
                if (!$matcher instanceof CodeScannerInterface) {
                    continue;
                }
                foreach ($matcher->getMatches() as $rawMatch) {
                    $match = Cast::array($rawMatch);
                    $line = Cast::int($match['line'] ?? 0);
                    $matches[] = [
                        'file' => $relativeName,
                        'line' => $line,
                        'indicator' => Cast::string($match['indicator'] ?? ''),
                        'message' => Cast::string($match['message'] ?? ''),
                        'lineContent' => $this->lineFromFile($absolutePath, $line),
                    ];
                }
            }
        } catch (Throwable) {
            return null;
        }

        return [
            'matches' => $matches,
            'effectiveCodeLines' => $statistics->getNumberOfEffectiveCodeLines(),
            'ignoredLines' => $statistics->getNumberOfIgnoredLines(),
        ];
    }

    private function lineFromFile(string $absolutePath, int $lineNumber): string
    {
        $content = file($absolutePath, \FILE_IGNORE_NEW_LINES);
        if (!is_array($content) || !isset($content[$lineNumber - 1])) {
            return '';
        }

        return trim($content[$lineNumber - 1]);
    }
}
