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

use KonradMichalik\Typo3AiMate\Command\Support\LabelLookup;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use PHPUnit\Framework\TestCase;

/**
 * LabelLookupTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class LabelLookupTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array{file: string, key: string, domain: bool}}>
     */
    public static function validReferences(): iterable
    {
        yield 'LLL with file path' => [
            'LLL:EXT:my_ext/Resources/Private/Language/locallang.xlf:plugin.title',
            ['file' => 'EXT:my_ext/Resources/Private/Language/locallang.xlf', 'key' => 'plugin.title', 'domain' => false],
        ];
        yield 'file path without LLL prefix' => [
            'EXT:my_ext/Resources/Private/Language/locallang_db.xlf:tx_myext.title',
            ['file' => 'EXT:my_ext/Resources/Private/Language/locallang_db.xlf', 'key' => 'tx_myext.title', 'domain' => false],
        ];
        yield 'translation domain' => [
            'core.common:cancel',
            ['file' => 'core.common', 'key' => 'cancel', 'domain' => true],
        ];
        yield 'translation domain with LLL prefix' => [
            'LLL:my_ext.sets.my_set:settings.title',
            ['file' => 'my_ext.sets.my_set', 'key' => 'settings.title', 'domain' => true],
        ];
    }

    /**
     * @param array{file: string, key: string, domain: bool} $expected
     */
    #[Test]
    #[DataProvider('validReferences')]
    public function parseReferenceSplitsFileAndKey(string $reference, array $expected): void
    {
        self::assertSame($expected, LabelLookup::parseReference($reference));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidReferences(): iterable
    {
        yield 'plain text' => ['Event list'];
        yield 'missing key' => ['LLL:EXT:my_ext/Resources/Private/Language/locallang.xlf'];
        yield 'empty key' => ['LLL:EXT:my_ext/Resources/Private/Language/locallang.xlf:'];
        yield 'domain without resource part' => ['core:cancel'];
        yield 'uppercase domain' => ['Core.Common:cancel'];
    }

    #[Test]
    #[DataProvider('invalidReferences')]
    public function parseReferenceRejectsWhatIsNoLabelReference(string $reference): void
    {
        self::assertNull(LabelLookup::parseReference($reference));
    }

    #[Test]
    public function extensionKeyComesFromThePathOrTheDomainPrefix(): void
    {
        self::assertSame('my_ext', LabelLookup::extensionKey('EXT:my_ext/Resources/Private/Language/locallang.xlf'));
        self::assertSame('core', LabelLookup::extensionKey('core.common'));
        self::assertNull(LabelLookup::extensionKey('/var/www/locallang.xlf'));
    }

    #[Test]
    public function flattenLegacyPrefersTheTargetOverTheSource(): void
    {
        $parsed = [
            'de' => [
                'plugin.title' => [['source' => 'Event list', 'target' => 'Veranstaltungsliste']],
                'plugin.description' => [['source' => 'Lists events']],
            ],
        ];

        self::assertSame(
            ['plugin.title' => 'Veranstaltungsliste', 'plugin.description' => 'Lists events'],
            LabelLookup::flattenLegacy($parsed, 'de'),
        );
        self::assertSame([], LabelLookup::flattenLegacy($parsed, 'fr'));
    }

    #[Test]
    public function flattenTakesTheFirstFormOfAPluralLabel(): void
    {
        self::assertSame(
            ['title' => 'Event list', 'count' => 'one event'],
            LabelLookup::flatten(['title' => 'Event list', 'count' => ['one event', '%d events']]),
        );
    }

    #[Test]
    public function groupTranslationsAssignsLocalePrefixedSiblingsToTheirFile(): void
    {
        self::assertSame(
            [
                'Resources/Private/Language/locallang.xlf' => ['de', 'fr'],
                'Resources/Private/Language/db.xlf' => [],
                'Resources/Private/Language/it.orphan.xlf' => [],
            ],
            LabelLookup::groupTranslations([
                'Resources/Private/Language/de.locallang.xlf',
                'Resources/Private/Language/fr.locallang.xlf',
                'Resources/Private/Language/locallang.xlf',
                'Resources/Private/Language/db.xlf',
                'Resources/Private/Language/it.orphan.xlf',
            ]),
        );
    }

    #[Test]
    public function isValidLocaleAcceptsLanguageAndRegionCodesOnly(): void
    {
        self::assertTrue(LabelLookup::isValidLocale('de'));
        self::assertTrue(LabelLookup::isValidLocale('de_CH'));
        self::assertTrue(LabelLookup::isValidLocale('pt-BR'));
        self::assertFalse(LabelLookup::isValidLocale('default'));
        self::assertFalse(LabelLookup::isValidLocale('../de'));
        self::assertFalse(LabelLookup::isValidLocale(''));
    }
}
