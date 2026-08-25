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
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function is_array;

/**
 * FlexFormMultiSheetTest.
 *
 * The sibling test declares its data structure inline, in one sheet, without a
 * sheetTitle. Real ones are a `FILE:EXT:` reference with several sheets, and the
 * per-sheet prefix in the diff only holds up against that shape.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class FlexFormMultiSheetTest extends FunctionalTestCase
{
    private const DATA_STRUCTURE = 'FILE:EXT:typo3_ai_mate/Tests/Functional/Fixtures/FlexForms/Listing.xml';

    /**
     * Three of these are declared by the structure, `settings.limit` is not: it
     * is the renamed field the tool exists to surface, and it sits in the same
     * sheet as its replacement.
     */
    private const STORED_FLEXFORM = <<<'XML'
        <?xml version="1.0" encoding="utf-8" standalone="yes" ?>
        <T3FlexForms>
            <data>
                <sheet index="sDEF">
                    <language index="lDEF">
                        <field index="settings.source">
                            <value index="vDEF">featured</value>
                        </field>
                        <field index="settings.limit">
                            <value index="vDEF">9</value>
                        </field>
                        <field index="settings.maxItems">
                            <value index="vDEF">12</value>
                        </field>
                    </language>
                </sheet>
                <sheet index="sAppearance">
                    <language index="lDEF">
                        <field index="settings.layout">
                            <value index="vDEF">list</value>
                        </field>
                    </language>
                </sheet>
            </data>
        </T3FlexForms>
        XML;

    protected array $coreExtensionsToLoad = [
        'install',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $config = &$GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config'];
        $config['ds'] = is_array($config['ds'] ?? null) ? ['default' => self::DATA_STRUCTURE] : self::DATA_STRUCTURE;
        unset($config);
        $this->get(TcaSchemaFactory::class)->rebuild($GLOBALS['TCA']);

        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert(
            'tt_content',
            ['uid' => 1, 'pid' => 1, 'CType' => 'list', 'header' => 'Software listing', 'pi_flexform' => self::STORED_FLEXFORM],
        );
    }

    #[Test]
    public function readsEverySheetOfAFileReferencedStructureAndKeepsThePrefix(): void
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:flexform:diff');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['table' => 'tt_content', 'uid' => '1']);
        $result = json_decode($tester->getDisplay(), true);

        self::assertSame(0, $exitCode);
        self::assertIsArray($result);
        self::assertTrue($result['dataStructureResolved']);

        // Both sheets are read, and a field is named by the sheet that declares
        // it: two sheets can carry the same field name.
        self::assertSame([
            'sDEF/settings.source' => 'featured',
            'sDEF/settings.maxItems' => '12',
            'sAppearance/settings.layout' => 'list',
        ], $result['matched']);

        self::assertSame(['sDEF/settings.limit' => '9'], $result['orphaned']);
        self::assertSame(0, $result['missingCount']);
    }
}
