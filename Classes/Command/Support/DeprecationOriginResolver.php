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
     * Extract the deprecated symbol candidates from a message. camelCase tokens
     * (an internal uppercase, e.g. getRequest, useNonce) are reliable identifier
     * signals — English prose words almost never contain them — plus any method
     * named after a -> or :: operator.
     *
     * @return list<string>
     */
    public function extractSymbols(string $message): array
    {
        $symbols = [];
        preg_match_all('/(?:->|::)\s*([A-Za-z_]\w{2,})/', $message, $methodMatches);
        foreach ($methodMatches[1] as $symbol) {
            $symbols[] = $symbol;
        }
        preg_match_all('/\b([a-z]+[A-Z][A-Za-z0-9]*)\b/', $message, $camelMatches);
        foreach ($camelMatches[1] as $symbol) {
            $symbols[] = $symbol;
        }

        return array_values(array_unique(array_filter(
            $symbols,
            static fn (string $symbol): bool => 3 <= strlen($symbol) && !in_array($symbol, self::STOP_WORDS, true),
        )));
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
        $symbols = $this->extractSymbols($message);
        if ([] === $symbols) {
            return [];
        }

        $origins = [];
        foreach ($this->ownFiles as $ownFile) {
            foreach ($this->matchSymbolsInFile($ownFile, $symbols) as $origin) {
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
     * @param list<string>                                        $symbols
     *
     * @return list<array{file: string, line: int, snippet: string, symbol: string, via: string, confidence: string}>
     */
    private function matchSymbolsInFile(array $ownFile, array $symbols): array
    {
        $pattern = '/\b(?:'.implode('|', array_map(preg_quote(...), $symbols)).')\b/';
        $origins = [];
        foreach (explode("\n", $ownFile['content']) as $index => $line) {
            if (1 === preg_match($pattern, $line, $matches)) {
                $origins[] = [
                    'file' => $ownFile['label'],
                    'line' => $index + 1,
                    'snippet' => trim($line),
                    'symbol' => $matches[0],
                    'via' => 'static',
                    'confidence' => 'low',
                ];
            }
        }

        return array_slice($origins, 0, self::MAX_ORIGINS);
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
