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

use KonradMichalik\Typo3AiMate\Command\Support\ListInput;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_slice;

/**
 * ListInputTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ListInputTest extends TestCase
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
    public function parseSplitsTrimsAndDeduplicates(): void
    {
        self::assertSame(
            ['actions-add', 'actions-edit'],
            ListInput::parse(' actions-add , actions-edit ,, actions-add '),
        );
    }

    #[Test]
    public function parseIsEmptyForANonString(): void
    {
        self::assertSame([], ListInput::parse(null));
    }

    #[Test]
    public function matchingIsCaseInsensitiveAndSorted(): void
    {
        self::assertSame(
            ['content-text', 'content-textmedia'],
            ListInput::matching('TEXT', self::REGISTERED),
        );
    }

    #[Test]
    public function suggestPrefersSubstringMatchesOverEditDistance(): void
    {
        $suggestions = ListInput::suggest('content', self::REGISTERED);

        self::assertSame(['content-text', 'content-textmedia'], array_slice($suggestions, 0, 2));
    }

    #[Test]
    public function suggestFindsTheNearestIdentifierForATypo(): void
    {
        self::assertContains('actions-delete', ListInput::suggest('action-delete', self::REGISTERED));
    }

    #[Test]
    public function suggestCapsSubstringMatchesAndSkipsTheEditDistancePass(): void
    {
        // Six substring matches against a limit of five: the cap has to apply,
        // and a sixth match must not be pushed out by a merely-similar name the
        // edit-distance pass would otherwise add.
        $registered = ['a-content', 'b-content', 'c-content', 'd-content', 'e-content', 'f-content', 'contant'];

        $suggestions = ListInput::suggest('content', $registered);

        self::assertCount(5, $suggestions);
        self::assertNotContains('contant', $suggestions);
    }

    #[Test]
    public function suggestIsEmptyWhenNothingIsClose(): void
    {
        self::assertSame([], ListInput::suggest('completely-unrelated-identifier', self::REGISTERED));
    }
}
