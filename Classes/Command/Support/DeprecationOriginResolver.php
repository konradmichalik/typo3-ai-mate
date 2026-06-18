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

namespace KonradMichalik\Typo3AiMate\Command\Support;

use function array_slice;
use function count;
use function in_array;
use function strlen;

/**
 * DeprecationOriginResolver.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
final readonly class DeprecationOriginResolver
{
    private const MAX_ORIGINS = 5;

    /**
     * Tokens that match the identifier patterns but carry no locating value.
     */
    private const STOP_WORDS = ['typo3', 'getInstance', '__construct'];

    /**
     * @param list<array{path: string, label: string, content: string}> $ownFiles
     */
    public function __construct(private array $ownFiles) {}

    /**
     * @return list<array{file: string, line: int, snippet: string, symbol: string, via: string, confidence: string}>
     */
    public function resolve(string $message, ?string $trace = null): array
    {
        $fromTrace = $this->fromTrace($trace);
        if ([] !== $fromTrace) {
            return $fromTrace;
        }

        return $this->fromStaticSearch($message);
    }

    /**
     * Parse method-call candidates from a deprecation message. A qualified call
     * (Class::method / Class->method) keeps its class so the search can demand
     * that the class is referenced in a file too — without that context a bare
     * method name like "add" matches every unrelated ->add() call (the reported
     * false positive). Standalone camelCase identifiers (e.g. useNonce) are
     * distinctive enough to search on their own.
     *
     * @return list<array{class: string|null, method: string}>
     */
    private function parseCalls(string $message): array
    {
        $calls = [];
        $qualifiedMethods = [];
        preg_match_all('/([A-Za-z_]\w*)\s*(?:::|->)\s*([A-Za-z_]\w+)/', $message, $qualified, \PREG_SET_ORDER);
        foreach ($qualified as $match) {
            $calls[] = ['class' => $match[1], 'method' => $match[2]];
            $qualifiedMethods[$match[2]] = true;
        }
        preg_match_all('/\b([a-z]+[A-Z][A-Za-z0-9]*)\b/', $message, $camel);
        foreach ($camel[1] as $method) {
            // Skip methods already seen qualified — their class context is stronger.
            if (!isset($qualifiedMethods[$method])) {
                $calls[] = ['class' => null, 'method' => $method];
            }
        }

        $result = [];
        $seen = [];
        foreach ($calls as $call) {
            $key = ($call['class'] ?? '').'::'.$call['method'];
            if (strlen($call['method']) < 3 || in_array($call['method'], self::STOP_WORDS, true) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $call;
        }

        return $result;
    }

    /**
     * @return list<array{file: string, line: int, snippet: string, symbol: string, via: string, confidence: string}>
     */
    private function fromTrace(?string $trace): array
    {
        if (null === $trace || '' === $trace) {
            return [];
        }

        // FileWriter backtrace frames: "#0 /abs/path/File.php(123): Class->method()".
        preg_match_all('/#\d+\s+(\/[^\(]+\.\w+)\((\d+)\)(?::\s*(.*))?/', $trace, $frames, \PREG_SET_ORDER);

        foreach ($frames as $frame) {
            $ownFile = $this->ownFileForPath($frame[1]);
            if (null === $ownFile) {
                continue;
            }

            return [[
                'file' => $ownFile['label'],
                'line' => (int) $frame[2],
                'snippet' => trim($frame[3] ?? ''),
                'symbol' => '',
                'via' => 'trace',
                'confidence' => 'high',
            ]];
        }

        return [];
    }

    /**
     * @return list<array{file: string, line: int, snippet: string, symbol: string, via: string, confidence: string}>
     */
    private function fromStaticSearch(string $message): array
    {
        $calls = $this->parseCalls($message);
        if ([] === $calls) {
            return [];
        }

        $origins = [];
        foreach ($this->ownFiles as $ownFile) {
            foreach ($this->matchCallsInFile($ownFile, $calls) as $origin) {
                $origins[] = $origin;
                if (count($origins) >= self::MAX_ORIGINS) {
                    return $origins;
                }
            }
        }

        return $origins;
    }

    /**
     * @param array{path: string, label: string, content: string} $ownFile
     * @param list<array{class: string|null, method: string}>     $calls
     *
     * @return list<array{file: string, line: int, snippet: string, symbol: string, via: string, confidence: string}>
     */
    private function matchCallsInFile(array $ownFile, array $calls): array
    {
        // A qualified call only counts when its class is referenced in the file.
        $active = array_values(array_filter(
            $calls,
            fn (array $call): bool => null === $call['class'] || $this->mentions($ownFile['content'], $call['class']),
        ));
        if ([] === $active) {
            return [];
        }

        $origins = [];
        foreach (explode("\n", $ownFile['content']) as $index => $line) {
            $call = $this->firstMatchingCall($line, $active);
            if (null !== $call) {
                $origins[] = [
                    'file' => $ownFile['label'],
                    'line' => $index + 1,
                    'snippet' => trim($line),
                    'symbol' => (null !== $call['class'] ? $call['class'].'::' : '').$call['method'],
                    'via' => 'static',
                    'confidence' => 'low',
                ];
            }
        }

        return array_slice($origins, 0, self::MAX_ORIGINS);
    }

    /**
     * @param list<array{class: string|null, method: string}> $calls
     *
     * @return array{class: string|null, method: string}|null
     */
    private function firstMatchingCall(string $line, array $calls): ?array
    {
        foreach ($calls as $call) {
            if (1 === preg_match('/\b'.preg_quote($call['method'], '/').'\b/', $line)) {
                return $call;
            }
        }

        return null;
    }

    private function mentions(string $content, string $class): bool
    {
        return 1 === preg_match('/\b'.preg_quote($class, '/').'\b/', $content);
    }

    /**
     * @return array{path: string, label: string, content: string}|null
     */
    private function ownFileForPath(string $path): ?array
    {
        foreach ($this->ownFiles as $ownFile) {
            if ($ownFile['path'] === $path) {
                return $ownFile;
            }
        }

        return null;
    }
}
