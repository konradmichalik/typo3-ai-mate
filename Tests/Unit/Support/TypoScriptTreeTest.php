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
    public function scopeReturnsAnErrorEnvelopeWhenThePathIsNotFound(): void
    {
        $tree = ['lib.' => ['foo.' => []]];

        self::assertSame(
            ['error' => 'Path "lib.missing" not found in resolved TypoScript.'],
            TypoScriptTree::scope($tree, 'lib.missing'),
        );
    }

    #[Test]
    public function scopeReturnsAnErrorEnvelopeWhenDescendingIntoAScalar(): void
    {
        $tree = ['lib.' => ['foo' => 'scalar']];

        self::assertSame(
            ['error' => 'Path "lib.foo.deeper" not found in resolved TypoScript.'],
            TypoScriptTree::scope($tree, 'lib.foo.deeper'),
        );
    }
}
