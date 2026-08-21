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

use KonradMichalik\Typo3AiMate\Command\Support\TcaRecordTypes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TcaRecordTypesTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class TcaRecordTypesTest extends TestCase
{
    #[Test]
    public function collapseLiftsTheFieldsEveryTypeCarriesIntoShared(): void
    {
        $collapsed = TcaRecordTypes::collapse([
            'text' => ['CType', 'header', 'bodytext'],
            'textmedia' => ['CType', 'header', 'bodytext', 'assets'],
            'div' => ['CType', 'header'],
        ]);

        self::assertSame(['CType', 'header'], $collapsed['shared']);
        self::assertSame([
            'text' => ['bodytext'],
            'textmedia' => ['bodytext', 'assets'],
            'div' => [],
        ], $collapsed['types']);
        self::assertArrayHasKey('_hint', $collapsed);
    }

    #[Test]
    public function collapseIsLossless(): void
    {
        $recordTypes = [
            'text' => ['CType', 'header', 'bodytext'],
            'textmedia' => ['CType', 'header', 'assets'],
        ];

        $collapsed = TcaRecordTypes::collapse($recordTypes);

        foreach ($recordTypes as $type => $fields) {
            // Set-wise, not order-wise: shared is lifted out of the middle of a
            // type's list, so the reassembled order can differ.
            self::assertEqualsCanonicalizing(
                $fields,
                [...$collapsed['shared'], ...$collapsed['types'][$type]],
                'shared plus the type entry reproduces the original field set.',
            );
        }
    }

    #[Test]
    public function collapseLeavesASingleTypeUncollapsed(): void
    {
        $collapsed = TcaRecordTypes::collapse(['text' => ['CType', 'bodytext']]);

        self::assertSame([], $collapsed['shared']);
        self::assertSame(['text' => ['CType', 'bodytext']], $collapsed['types']);
        self::assertArrayNotHasKey('_hint', $collapsed);
    }

    #[Test]
    public function limitToFieldsReducesEveryTypeToTheRequestedFields(): void
    {
        $limited = TcaRecordTypes::limitToFields([
            'text' => ['CType', 'header', 'bodytext'],
            'div' => ['CType', 'header'],
        ], ['bodytext']);

        self::assertSame(['text' => ['bodytext'], 'div' => []], $limited);
    }
}
