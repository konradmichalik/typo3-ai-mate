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

namespace KonradMichalik\Typo3AiMate\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * LabelsCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class LabelsCommandTest extends FunctionalTestCase
{
    private const FILE = 'EXT:labels_fixture/Resources/Private/Language/locallang.xlf';

    protected bool $initializeDatabase = false;

    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
        __DIR__.'/../Fixtures/Extensions/labels_fixture',
    ];

    #[Test]
    public function explainsTheArgumentsWhenCalledWithoutAny(): void
    {
        [$exitCode, $result] = $this->runCommand([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('references', (string) $result['_hint']);
    }

    #[Test]
    public function confirmsAnExistingLabelWithItsDefaultText(): void
    {
        [$exitCode, $result] = $this->runCommand(['--references' => 'LLL:'.self::FILE.':plugin.title']);

        self::assertSame(0, $exitCode);
        self::assertSame(1, $result['checked']);
        $label = $this->reference($result, 'LLL:'.self::FILE.':plugin.title');
        self::assertTrue($label['exists']);
        self::assertSame(self::FILE, $label['file']);
        self::assertSame('Event list', $label['default']);
    }

    #[Test]
    public function resolvesACoreLabel(): void
    {
        $reference = 'LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:cancel';
        [, $result] = $this->runCommand(['--references' => $reference]);

        $label = $this->reference($result, $reference);
        self::assertTrue($label['exists']);
        self::assertNotSame('', $label['default']);
    }

    #[Test]
    public function answersAMissingKeyWithTheClosestKeysOfTheFile(): void
    {
        $reference = 'LLL:'.self::FILE.':plugin.titel';
        [$exitCode, $result] = $this->runCommand(['--references' => $reference]);

        self::assertSame(0, $exitCode);
        $label = $this->reference($result, $reference);
        self::assertFalse($label['exists']);
        self::assertContains('plugin.title', (array) $label['suggestions']);
        self::assertStringContainsString('not defined', (string) $label['_hint']);
    }

    #[Test]
    public function answersAMissingFileWithTheLabelFilesTheExtensionHas(): void
    {
        $reference = 'LLL:EXT:labels_fixture/Resources/Private/Language/locallang_be.xlf:title';
        [, $result] = $this->runCommand(['--references' => $reference]);

        $label = $this->reference($result, $reference);
        self::assertFalse($label['exists']);
        self::assertFalse($label['fileExists']);
        self::assertStringContainsString('does not exist', (string) $label['_hint']);
        self::assertContains(self::FILE, (array) $label['labelFiles']);
        self::assertContains('EXT:labels_fixture/Resources/Private/Language/locallang_db.xlf', (array) $label['labelFiles']);
        self::assertContains('EXT:labels_fixture/Resources/Private/Language/Plugins/Calendar/locallang_calendar.xlf', (array) $label['labelFiles']);
        self::assertNotContains('EXT:labels_fixture/Resources/Private/Language/de.locallang.xlf', (array) $label['labelFiles']);
    }

    #[Test]
    public function namesAnExtensionThatIsNotLoaded(): void
    {
        $reference = 'LLL:EXT:not_loaded/Resources/Private/Language/locallang.xlf:title';
        [, $result] = $this->runCommand(['--references' => $reference]);

        $label = $this->reference($result, $reference);
        self::assertFalse($label['exists']);
        self::assertStringContainsString('not_loaded', (string) $label['_hint']);
    }

    #[Test]
    public function rejectsTextThatIsNoLabelReference(): void
    {
        [, $result] = $this->runCommand(['--references' => 'Event list']);

        $label = $this->reference($result, 'Event list');
        self::assertFalse($label['valid']);
    }

    #[Test]
    public function reportsWhetherALabelIsTranslatedForTheRequestedLocale(): void
    {
        $translated = 'LLL:'.self::FILE.':plugin.title';
        $untranslated = 'LLL:'.self::FILE.':plugin.description';
        [$exitCode, $result] = $this->runCommand(['--references' => $translated.','.$untranslated, '--locale' => 'de']);

        self::assertSame(0, $exitCode);
        $translation = (array) $this->reference($result, $translated)['translation'];
        self::assertTrue($translation['translated']);
        self::assertSame('Veranstaltungsliste', $translation['value']);

        $fallback = (array) $this->reference($result, $untranslated)['translation'];
        self::assertFalse($fallback['translated']);
        self::assertSame('Lists upcoming events', $fallback['value']);
    }

    #[Test]
    public function rejectsAnInvalidLocale(): void
    {
        [$exitCode, $result] = $this->runCommand(['--references' => 'LLL:'.self::FILE.':plugin.title', '--locale' => '../de']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('locale', (string) $result['error']);
    }

    #[Test]
    public function answersATranslationDomainReferenceAccordingToTheVersion(): void
    {
        $reference = 'labels_fixture.messages:plugin.title';
        [, $result] = $this->runCommand(['--references' => $reference]);

        $label = $this->reference($result, $reference);
        if ((new Typo3Version())->getMajorVersion() >= 14) {
            self::assertTrue($label['exists']);
            self::assertSame(self::FILE, $label['file']);
            self::assertSame('Event list', $label['default']);

            return;
        }

        self::assertFalse($label['exists']);
        self::assertStringContainsString('v14', (string) $label['_hint']);
    }

    #[Test]
    public function listsTheLabelFilesOfAnExtensionWithKeyCountAndLocales(): void
    {
        [$exitCode, $result] = $this->runCommand(['--extension' => 'labels_fixture']);

        self::assertSame(0, $exitCode);
        self::assertSame('labels_fixture', $result['extension']);
        $files = (array) $result['files'];
        self::assertCount(4, $files);

        $main = (array) $files[self::FILE];
        self::assertSame(2, $main['keys']);
        self::assertSame(['de'], $main['locales']);
        if ((new Typo3Version())->getMajorVersion() >= 14) {
            self::assertSame('labels_fixture.messages', $main['domain']);
        }

        $nested = (array) $files['EXT:labels_fixture/Resources/Private/Language/Plugins/Calendar/locallang_calendar.xlf'];
        self::assertSame(1, $nested['keys']);

        $broken = (array) $files['EXT:labels_fixture/Resources/Private/Language/broken.xlf'];
        self::assertArrayNotHasKey('keys', $broken);
        self::assertIsString($broken['error']);
    }

    #[Test]
    public function reportsABrokenLabelFileOnItsOwnReferenceOnly(): void
    {
        $broken = 'LLL:EXT:labels_fixture/Resources/Private/Language/broken.xlf:unclosed';
        $intact = 'LLL:'.self::FILE.':plugin.title';
        [$exitCode, $result] = $this->runCommand(['--references' => $broken.','.$intact]);

        self::assertSame(0, $exitCode);
        self::assertFalse($this->reference($result, $broken)['exists']);
        self::assertIsString($this->reference($result, $broken)['error']);
        self::assertTrue($this->reference($result, $intact)['exists']);
    }

    #[Test]
    public function rejectsAReferenceWithoutExtensionKey(): void
    {
        [, $result] = $this->runCommand(['--references' => 'LLL:EXT:/locallang.xlf:title']);

        self::assertFalse($this->reference($result, 'LLL:EXT:/locallang.xlf:title')['valid']);
    }

    #[Test]
    public function acceptsAHyphenatedLocale(): void
    {
        [$exitCode, $result] = $this->runCommand(['--references' => 'LLL:'.self::FILE.':plugin.title', '--locale' => 'de-CH']);

        self::assertSame(0, $exitCode);
        self::assertSame('de_CH', ((array) $this->reference($result, 'LLL:'.self::FILE.':plugin.title')['translation'])['locale']);
    }

    #[Test]
    public function rejectsAnExtensionThatIsNotLoaded(): void
    {
        [$exitCode, $result] = $this->runCommand(['--extension' => 'not_loaded']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('not_loaded', (string) $result['error']);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function reference(array $result, string $reference): array
    {
        $references = (array) $result['references'];
        self::assertArrayHasKey($reference, $references);

        return (array) $references[$reference];
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:labels:lookup');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
