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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Service;

use KonradMichalik\Typo3AiMate\Service\FluidResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FluidResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class FluidResolverTest extends TestCase
{
    #[Test]
    public function orderedPathsSortsByNumericKeyDescending(): void
    {
        $ordered = FluidResolver::orderedPaths([
            '10' => 'EXT:base/Resources/Private/Templates/',
            '30' => 'EXT:override/Resources/Private/Templates/',
            '20' => 'EXT:mid/Resources/Private/Templates/',
        ]);

        self::assertSame([
            ['key' => '30', 'path' => 'EXT:override/Resources/Private/Templates/'],
            ['key' => '20', 'path' => 'EXT:mid/Resources/Private/Templates/'],
            ['key' => '10', 'path' => 'EXT:base/Resources/Private/Templates/'],
        ], $ordered);
    }

    #[Test]
    public function orderedPathsIgnoresNonScalarEntries(): void
    {
        $ordered = FluidResolver::orderedPaths([
            '10' => 'EXT:base/Templates/',
            '10.' => ['nested' => 'object'],
        ]);

        self::assertSame([['key' => '10', 'path' => 'EXT:base/Templates/']], $ordered);
    }

    #[Test]
    public function pickExistingReturnsTheFirstCandidateThatContainsTheFile(): void
    {
        $root = sys_get_temp_dir().'/typo3-ai-mate-fluid-'.bin2hex(random_bytes(8));
        $winning = $root.'/override';
        mkdir($winning, 0777, true);
        touch($winning.'/List.html');

        $result = FluidResolver::pickExisting([
            ['absolute' => $root.'/missing'],
            ['absolute' => $winning],
        ], 'List', 'html');

        self::assertSame($winning.'/List.html', $result['file']);
        self::assertSame([$root.'/missing/List.html', $winning.'/List.html'], $result['checked']);

        unlink($winning.'/List.html');
        rmdir($winning);
        rmdir($root);
    }

    #[Test]
    public function pickExistingRejectsPathTraversalOutsideTheCandidateRoot(): void
    {
        $root = sys_get_temp_dir().'/typo3-ai-mate-fluid-'.bin2hex(random_bytes(8));
        $base = $root.'/Templates';
        mkdir($base, 0777, true);
        file_put_contents($root.'/secret.html', 'x'); // sibling of the root path, outside it

        $result = FluidResolver::pickExisting([['absolute' => $base]], '../secret', 'html');

        self::assertNull($result['file']);

        unlink($root.'/secret.html');
        rmdir($base);
        rmdir($root);
    }

    #[Test]
    public function pickExistingReturnsNullFileWhenNoCandidateMatches(): void
    {
        $result = FluidResolver::pickExisting([
            ['absolute' => '/does/not/exist'],
        ], 'News/List', 'html');

        self::assertNull($result['file']);
        self::assertSame(['/does/not/exist/News/List.html'], $result['checked']);
    }
}
