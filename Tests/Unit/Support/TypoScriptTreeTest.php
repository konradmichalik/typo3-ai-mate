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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Support;

use KonradMichalik\Typo3AiMate\Support\TypoScriptTree;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * TypoScriptTreeTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TypoScriptTreeTest extends TestCase
{
    #[Test]
    public function getFollowsTrailingDotObjectKeys(): void
    {
        $tree = ['lib.' => ['foo.' => ['value' => '10', '10' => 'TEXT']]];

        self::assertSame(['value' => '10', '10' => 'TEXT'], TypoScriptTree::get($tree, 'lib.foo'));
    }

    #[Test]
    public function getReachesAFlatKeyThatCarriesDotsItself(): void
    {
        // Site settings arrive as one key with dots in its name. scope() offers
        // exactly these as the siblings to try next, so refusing them here means
        // handing back a name and then rejecting it.
        $tree = ['riversideSite.cardColumns' => '4', 'config.' => ['no_cache' => '0']];

        self::assertSame('4', TypoScriptTree::get($tree, 'riversideSite.cardColumns'));
    }

    #[Test]
    public function getPrefersAFlatKeyOverDescendingIntoNothing(): void
    {
        $tree = ['riversideSite.' => ['apiEndpoint' => 'https://example.org'], 'riversideSite.cardColumns' => '4'];

        self::assertSame('https://example.org', TypoScriptTree::get($tree, 'riversideSite.apiEndpoint'));
        self::assertSame('4', TypoScriptTree::get($tree, 'riversideSite.cardColumns'));
    }

    #[Test]
    public function getPrefersAnExactFlatKeyOverDescendingIntoTheSameName(): void
    {
        // Both spellings can coexist in a resolved tree. The exact match of the
        // whole remaining path is the more specific answer and wins; a path that
        // reaches past such a key still descends.
        $collision = ['riversideSite.' => ['cardColumns' => 'nested'], 'riversideSite.cardColumns' => 'flat'];
        self::assertSame('flat', TypoScriptTree::get($collision, 'riversideSite.cardColumns'));

        $deeper = ['a.' => ['b.' => ['c' => 'deep']], 'a.b' => ['c' => 'flat']];
        self::assertSame('deep', TypoScriptTree::get($deeper, 'a.b.c'));
    }

    #[Test]
    public function getTrimsLeadingAndTrailingDotsFromThePath(): void
    {
        $tree = ['lib.' => ['foo.' => ['bar' => 'baz']]];

        self::assertSame(['bar' => 'baz'], TypoScriptTree::get($tree, '.lib.foo.'));
    }

    #[Test]
    public function getFallsBackToTheScalarKeyWhenNoObjectKeyExists(): void
    {
        $tree = ['lib.' => ['foo' => 'scalar']];

        self::assertSame('scalar', TypoScriptTree::get($tree, 'lib.foo'));
    }

    #[Test]
    public function getReturnsNullWhenThePathIsNotFound(): void
    {
        $tree = ['lib.' => ['foo.' => []]];

        self::assertNull(TypoScriptTree::get($tree, 'lib.missing'));
    }

    #[Test]
    public function getReturnsNullWhenDescendingIntoAScalar(): void
    {
        $tree = ['lib.' => ['foo' => 'scalar']];

        self::assertNull(TypoScriptTree::get($tree, 'lib.foo.deeper'));
    }

    #[Test]
    public function scopeReturnsTheNodeWhenFound(): void
    {
        $tree = ['lib.' => ['foo.' => ['bar' => 'baz']]];

        self::assertSame(['bar' => 'baz'], TypoScriptTree::scope($tree, 'lib.foo'));
    }

    #[Test]
    public function scopeReportsTheSiblingsBelowTheDeepestResolvedSegmentOnAMiss(): void
    {
        $tree = ['lib.' => ['foo.' => [], 'bar.' => [], 'baz' => 'scalar']];

        $miss = TypoScriptTree::scope($tree, 'lib.missing');

        self::assertIsArray($miss);
        self::assertSame('lib.missing', $miss['path']);
        self::assertFalse($miss['found']);
        self::assertSame('lib', $miss['resolvedUpTo']);
        self::assertSame(['bar', 'baz', 'foo'], $miss['siblings']);
        self::assertSame(3, $miss['siblingCount']);
        self::assertStringContainsString('"lib" does', self::hint($miss));
    }

    #[Test]
    public function scopeReportsTheTopLevelKeysWhenNothingOfThePathResolves(): void
    {
        $tree = ['lib.' => [], 'page' => 'PAGE'];

        $miss = TypoScriptTree::scope($tree, 'nope.deeper');

        self::assertIsArray($miss);
        self::assertNull($miss['resolvedUpTo']);
        self::assertSame(['lib', 'page'], $miss['siblings']);
        self::assertStringContainsString('top-level', self::hint($miss));
    }

    #[Test]
    public function scopeSaysSoWhenThePathDescendsIntoAValue(): void
    {
        $tree = ['lib.' => ['foo' => 'scalar']];

        $miss = TypoScriptTree::scope($tree, 'lib.foo.deeper');

        self::assertIsArray($miss);
        self::assertFalse($miss['found']);
        self::assertSame('lib.foo', $miss['resolvedUpTo']);
        self::assertSame([], $miss['siblings']);
        self::assertStringContainsString('is a value, not a branch', self::hint($miss));
    }

    #[Test]
    public function scopeNamesTheTreeItSearchedInTheHint(): void
    {
        $miss = TypoScriptTree::scope(['mod.' => []], 'TCEFORM.pages', 'resolved TSconfig');

        self::assertIsArray($miss);
        self::assertStringContainsString('resolved TSconfig', self::hint($miss));
    }

    #[Test]
    public function scopeCapsTheSiblingListAndSaysHowManyWereOmitted(): void
    {
        $tree = [];
        for ($i = 0; $i < 60; ++$i) {
            $tree[sprintf('key%02d.', $i)] = [];
        }

        $miss = TypoScriptTree::scope($tree, 'missing');

        self::assertIsArray($miss);
        self::assertSame(60, $miss['siblingCount']);
        self::assertCount(40, (array) $miss['siblings']);
        self::assertStringContainsString('first 40 of 60', self::hint($miss));
    }

    #[Test]
    public function summarizeDescribesTopLevelKeysAndAddsAHint(): void
    {
        $tree = [
            'lib.' => ['foo.' => [], 'bar.' => []],
            'config.' => ['no_cache' => '0'],
            'page' => 'PAGE',
        ];

        $summary = TypoScriptTree::summarize($tree);

        self::assertSame('{2 keys}', $summary['lib.']);
        self::assertSame('{1 keys}', $summary['config.']);
        self::assertSame('PAGE', $summary['page']);
        self::assertArrayHasKey('_hint', $summary);
    }

    #[Test]
    public function summarizeCapsLongScalarPreviews(): void
    {
        $summary = TypoScriptTree::summarize(['long' => str_repeat('x', 200)]);

        self::assertStringEndsWith('…', $summary['long']);
        self::assertLessThan(200, mb_strlen($summary['long']));
    }

    #[Test]
    public function redactSecretsMasksScalarValuesUnderSecretKeys(): void
    {
        $tree = [
            'plugin.' => [
                'tx_foo.' => [
                    'settings.' => [
                        'apiKey' => 'live_abc123',
                        'password' => 'hunter2',
                        'endpoint' => 'https://api.example.com',
                    ],
                ],
            ],
            'config.' => ['no_cache' => '0'],
        ];

        $expected = [
            'plugin.' => [
                'tx_foo.' => [
                    'settings.' => [
                        'apiKey' => '***',
                        'password' => '***',
                        'endpoint' => 'https://api.example.com',
                    ],
                ],
            ],
            'config.' => ['no_cache' => '0'],
        ];

        self::assertSame($expected, TypoScriptTree::redactSecrets($tree));
    }

    #[Test]
    public function redactSecretsLeavesSecretNamedObjectNodesTraversable(): void
    {
        $tree = ['secret.' => ['value' => 'kept']];

        // A secret-named *object* node (trailing dot) is descended into, not masked.
        self::assertSame(['secret.' => ['value' => 'kept']], TypoScriptTree::redactSecrets($tree));
    }

    /**
     * @param array<mixed> $miss
     */
    private static function hint(array $miss): string
    {
        $hint = $miss['_hint'] ?? null;
        self::assertIsString($hint);

        return $hint;
    }
}
