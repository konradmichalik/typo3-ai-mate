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

namespace KonradMichalik\Typo3AiMate\Command\Support;

use KonradMichalik\Typo3AiMate\Support\Cast;
use PhpParser\{NodeTraverser, NodeVisitor, ParserFactory, PhpVersion};
use PhpParser\NodeVisitor\NameResolver;
use RuntimeException;
use Throwable;
use TYPO3\CMS\Install\ExtensionScanner\CodeScannerInterface;
use TYPO3\CMS\Install\ExtensionScanner\Php\MatcherFactory;

use function explode;

/**
 * PhpFileScanner.
 *
 * Runs the core extension scanner matchers against a single PHP file.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class PhpFileScanner
{
    // TYPO3 14.3.11 moved the core visitors into the Visitor sub-namespace.
    private const VISITOR_NAMESPACES = [
        'TYPO3\\CMS\\Install\\ExtensionScanner\\Php\\Visitor\\',
        'TYPO3\\CMS\\Install\\ExtensionScanner\\Php\\',
    ];

    /**
     * @param list<array{class: class-string, configurationArray: array<mixed>}> $matcherConfigurations
     *
     * @return array{matches: list<array<string, mixed>>, effectiveCodeLines: int, ignoredLines: int}|null
     */
    public function scan(string $absolutePath, string $relativeName, array $matcherConfigurations): ?array
    {
        $code = @file_get_contents($absolutePath);
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
                // The throwing error handler never yields null.
                // @codeCoverageIgnoreStart
                return null;
                // @codeCoverageIgnoreEnd
            }

            // First pass: resolve `use` aliases to fully qualified names so the
            // matchers (and GeneratorClassesResolver) see reliable class names.
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $statements = $traverser->traverse($statements);

            // Second pass: run the resolvers, the statistics collector and all matchers.
            $traverser = new NodeTraverser();
            $traverser->addVisitor($this->coreVisitor('GeneratorClassesResolver'));
            $statistics = $this->coreVisitor('CodeStatistics');
            $traverser->addVisitor($statistics);

            $matchers = (new MatcherFactory())->createAll($matcherConfigurations);
            foreach ($matchers as $matcher) {
                if ($matcher instanceof NodeVisitor) {
                    $traverser->addVisitor($matcher);
                }
            }
            $traverser->traverse($statements);

            $matches = $this->collectMatches($matchers, $relativeName, explode("\n", $code));
        } catch (Throwable) {
            return null;
        }

        return [
            'matches' => $matches,
            'effectiveCodeLines' => method_exists($statistics, 'getNumberOfEffectiveCodeLines') ? Cast::int($statistics->getNumberOfEffectiveCodeLines()) : 0,
            'ignoredLines' => method_exists($statistics, 'getNumberOfIgnoredLines') ? Cast::int($statistics->getNumberOfIgnoredLines()) : 0,
        ];
    }

    private function coreVisitor(string $shortName): NodeVisitor
    {
        foreach (self::VISITOR_NAMESPACES as $namespace) {
            $class = $namespace.$shortName;
            if (class_exists($class) && is_subclass_of($class, NodeVisitor::class)) {
                return new $class();
            }
        }

        throw new RuntimeException('Extension scanner visitor '.$shortName.' not found in EXT:install.', 1794215680);
    }

    /**
     * @param array<mixed> $matchers
     * @param list<string> $lines    the file's lines, already read for parsing
     *
     * @return list<array<string, mixed>>
     */
    private function collectMatches(array $matchers, string $relativeName, array $lines): array
    {
        $matches = [];
        foreach ($matchers as $matcher) {
            if (!$matcher instanceof CodeScannerInterface) {
                // MatcherFactory::createAll() already rejects such matchers.
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }
            foreach ($matcher->getMatches() as $rawMatch) {
                $match = Cast::array($rawMatch);
                $line = Cast::int($match['line'] ?? 0);
                $matches[] = [
                    'file' => $relativeName,
                    'line' => $line,
                    'indicator' => Cast::string($match['indicator'] ?? ''),
                    'message' => Cast::string($match['message'] ?? ''),
                    'lineContent' => trim($lines[$line - 1] ?? ''),
                ];
            }
        }

        return $matches;
    }
}
