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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command;

use KonradMichalik\Typo3AiMate\Command\TypoScriptCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TypoScriptCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TypoScriptCommandTest extends TestCase
{
    #[Test]
    public function scopeFollowsTrailingDotObjectKeys(): void
    {
        $tree = ['lib.' => ['foo.' => ['value' => '10', '10' => 'TEXT']]];

        self::assertSame(['value' => '10', '10' => 'TEXT'], TypoScriptCommand::scope($tree, 'lib.foo'));
    }

    #[Test]
    public function scopeTrimsLeadingAndTrailingDotsFromThePath(): void
    {
        $tree = ['lib.' => ['foo.' => ['bar' => 'baz']]];

        self::assertSame(['bar' => 'baz'], TypoScriptCommand::scope($tree, '.lib.foo.'));
    }

    #[Test]
    public function scopeFallsBackToTheScalarKeyWhenNoObjectKeyExists(): void
    {
        $tree = ['lib.' => ['foo' => 'scalar']];

        self::assertSame('scalar', TypoScriptCommand::scope($tree, 'lib.foo'));
    }

    #[Test]
    public function scopeReturnsAnErrorWhenThePathIsNotFound(): void
    {
        $tree = ['lib.' => ['foo.' => []]];

        self::assertSame(
            ['error' => 'Path "lib.missing" not found in resolved TypoScript.'],
            TypoScriptCommand::scope($tree, 'lib.missing'),
        );
    }

    #[Test]
    public function scopeReturnsAnErrorWhenDescendingIntoAScalar(): void
    {
        $tree = ['lib.' => ['foo' => 'scalar']];

        self::assertSame(
            ['error' => 'Path "lib.foo.deeper" not found in resolved TypoScript.'],
            TypoScriptCommand::scope($tree, 'lib.foo.deeper'),
        );
    }
}
