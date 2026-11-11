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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command\Support;

use KonradMichalik\Typo3AiMate\Command\Support\IconLookup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * IconLookupTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class IconLookupTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const REGISTERED = [
        'actions-add',
        'actions-edit',
        'actions-delete',
        'content-text',
        'content-textmedia',
        'tx-myext-plugin',
    ];

    #[Test]
    public function groupCountsCountsByLeadingSegment(): void
    {
        self::assertSame(
            ['actions' => 3, 'content' => 2, 'tx' => 1],
            IconLookup::groupCounts(self::REGISTERED),
        );
    }

    #[Test]
    public function sourceReadsTheSpriteReferenceFilePathOrGlyphName(): void
    {
        self::assertSame(
            'EXT:core/Resources/Public/Icons/T3Icons/sprites/actions.svg#actions-plus',
            IconLookup::source(['options' => ['sprite' => 'EXT:core/Resources/Public/Icons/T3Icons/sprites/actions.svg#actions-plus']]),
        );
        self::assertSame(
            'EXT:my_ext/Resources/Public/Icons/plugin.svg',
            IconLookup::source(['options' => ['source' => 'EXT:my_ext/Resources/Public/Icons/plugin.svg']]),
        );
        self::assertSame('fa-plus', IconLookup::source(['options' => ['name' => 'fa-plus']]));
        self::assertSame('', IconLookup::source([]));
    }

    #[Test]
    public function providingExtensionIsTheKeyFromAnExtSourcePath(): void
    {
        self::assertSame('my_ext', IconLookup::providingExtension('EXT:my_ext/Resources/Public/Icons/plugin.svg'));
        self::assertNull(IconLookup::providingExtension('fa-plus'));
        self::assertNull(IconLookup::providingExtension(''));
    }

    #[Test]
    public function providerNameKeepsOnlyTheShortClassName(): void
    {
        self::assertSame('SvgIconProvider', IconLookup::providerName('TYPO3\\CMS\\Core\\Imaging\\IconProvider\\SvgIconProvider'));
        self::assertSame('', IconLookup::providerName(''));
    }
}
