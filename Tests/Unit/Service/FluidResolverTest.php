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
    public function pickExistingSkipsCandidatesWithoutAnAbsolutePath(): void
    {
        $result = FluidResolver::pickExisting([['absolute' => '']], 'News/List', 'html');

        self::assertNull($result['file']);
        self::assertSame([], $result['checked']);
    }

    #[Test]
    public function pickExistingReturnsNullFileWhenNoCandidateMatches(): void
    {
        $result = FluidResolver::pickExisting([
            ['absolute' => '/does/not/exist'],
        ], 'News/List', 'html');

        self::assertNull($result['file']);
        self::assertFalse($result['found']);
        self::assertSame(['/does/not/exist/News/List.html'], $result['checked']);
    }

    #[Test]
    public function viewPathCandidatesNamesEveryPathThatDeclaresARootPath(): void
    {
        $setup = [
            'plugin.' => [
                'tx_news_pi1.' => ['view.' => ['templateRootPaths.' => ['10' => 'EXT:news/Templates/']]],
                'tx_other.' => ['settings.' => ['limit' => '5']],
            ],
            'lib.' => ['contentElement.' => ['view.' => ['layoutRootPaths.' => ['10' => 'EXT:fluid/Layouts/']]]],
            'page' => 'PAGE',
        ];

        self::assertSame(
            ['lib.contentElement', 'plugin.tx_news_pi1'],
            FluidResolver::viewPathCandidates($setup),
        );
    }

    #[Test]
    public function viewPathCandidatesIgnoresAViewNodeWithoutRootPaths(): void
    {
        $setup = ['plugin.' => ['tx_foo.' => ['view.' => ['pluginNamespace' => 'tx_foo']]]];

        self::assertSame([], FluidResolver::viewPathCandidates($setup));
    }

    #[Test]
    public function viewPathCandidatesIgnoresARootPathBlockWithoutAUsablePath(): void
    {
        // A candidate the caller cannot resolve is another guess, not an answer:
        // both of these declare the key but carry no path a chain can be built
        // from, which is exactly what makes viewPathFound false.
        $setup = [
            'plugin.' => [
                'tx_empty.' => ['view.' => ['templateRootPaths.' => []]],
                'tx_nested.' => ['view.' => ['partialRootPaths.' => ['10.' => ['override' => 'x']]]],
            ],
        ];

        self::assertSame([], FluidResolver::viewPathCandidates($setup));
    }

    #[Test]
    public function viewPathCandidatesStopsAtTheDepthCap(): void
    {
        $deep = ['view.' => ['templateRootPaths.' => ['10' => 'EXT:deep/Templates/']]];
        for ($i = 0; $i < 12; ++$i) {
            $deep = ['level'.$i.'.' => $deep];
        }

        self::assertSame([], FluidResolver::viewPathCandidates($deep));
    }
}
