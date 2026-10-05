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

use KonradMichalik\Typo3AiMate\Command\Support\ChangelogText;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ChangelogTextTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ChangelogTextTest extends TestCase
{
    #[Test]
    public function queryWordsLowercasesAndSplitsOnWhitespace(): void
    {
        self::assertSame(['sys_file_reference', 'uid'], ChangelogText::queryWords('  SYS_FILE_REFERENCE   uid '));
    }

    #[Test]
    public function queryWordsIsEmptyForABlankQuery(): void
    {
        self::assertSame([], ChangelogText::queryWords('   '));
    }

    #[Test]
    public function matchesAllWordsRequiresEveryWordToBePresent(): void
    {
        self::assertTrue(ChangelogText::matchesAllWords('the quick brown fox', ['quick', 'fox']));
        self::assertFalse(ChangelogText::matchesAllWords('the quick brown fox', ['quick', 'dog']));
    }

    #[Test]
    public function matchesAllWordsIsFalseForAnEmptyWordList(): void
    {
        self::assertFalse(ChangelogText::matchesAllWords('anything', []));
    }

    #[Test]
    public function parseFilenameExtractsTypeAndIssueNumber(): void
    {
        self::assertSame(
            ['type' => 'Breaking', 'issue' => 108304],
            ChangelogText::parseFilename('Breaking-108304-PopulateExtensionTitleFromComposerJson.rst'),
        );
    }

    #[Test]
    public function parseFilenameReturnsNullForAnUnrecognisedFilename(): void
    {
        self::assertNull(ChangelogText::parseFilename('Howto.rst'));
    }

    #[Test]
    public function extractTitleReadsTheHumanReadableHeadline(): void
    {
        $content = <<<'RST'
            ..  include:: /Includes.rst.txt

            ..  _breaking-108304-1764058005:

            ===============================================================
            Breaking: #108304 - Populate extension title from composer.json
            ===============================================================

            See :issue:`108304`
            RST;

        self::assertSame('Populate extension title from composer.json', ChangelogText::extractTitle($content));
    }

    #[Test]
    public function extractTitleReturnsNullWhenNoHeadlineIsFound(): void
    {
        self::assertNull(ChangelogText::extractTitle('no headline here'));
    }

    #[Test]
    public function excerptWindowsAroundTheFirstMatchAndMarksTruncation(): void
    {
        $content = str_repeat('x', 300).'NEEDLE'.str_repeat('y', 300);

        $excerpt = ChangelogText::excerpt($content, ['needle']);

        self::assertStringContainsString('NEEDLE', $excerpt);
        self::assertStringStartsWith('…', $excerpt);
        self::assertStringEndsWith('…', $excerpt);
    }

    #[Test]
    public function excerptDoesNotPrefixWhenTheMatchIsNearTheStart(): void
    {
        $content = 'NEEDLE'.str_repeat('y', 300);

        $excerpt = ChangelogText::excerpt($content, ['needle']);

        self::assertStringStartsNotWith('…', $excerpt);
    }
}
