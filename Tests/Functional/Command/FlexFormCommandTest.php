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
use function json_encode;

/**
 * FlexFormCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class FlexFormCommandTest extends FunctionalTestCase
{
    /**
     * The data structure a record's pi_flexform resolves to. It declares
     * settings.maxItems, while the record below still stores settings.limit —
     * the rename this tool exists to surface.
     */
    private const DATA_STRUCTURE = <<<'XML'
        <T3DataStructure>
            <sheets>
                <sDEF>
                    <ROOT>
                        <type>array</type>
                        <el>
                            <settings.maxItems>
                                <label>Max items</label>
                                <config>
                                    <type>number</type>
                                    <default>6</default>
                                </config>
                            </settings.maxItems>
                            <settings.plain>
                                <label>Plain</label>
                                <config>
                                    <type>input</type>
                                </config>
                            </settings.plain>
                        </el>
                    </ROOT>
                </sDEF>
            </sheets>
        </T3DataStructure>
        XML;

    private const STORED_FLEXFORM = <<<'XML'
        <?xml version="1.0" encoding="utf-8" standalone="yes" ?>
        <T3FlexForms>
            <data>
                <sheet index="sDEF">
                    <language index="lDEF">
                        <field index="settings.limit">
                            <value index="vDEF">9</value>
                        </field>
                        <field index="settings.plain">
                            <value index="vDEF">kept</value>
                        </field>
                    </language>
                </sheet>
            </data>
        </T3FlexForms>
        XML;

    /**
     * A section stores its items as nested arrays rather than as a scalar value,
     * and the item content is arbitrary editor input.
     */
    private const STORED_SECTION = <<<'XML'
        <?xml version="1.0" encoding="utf-8" standalone="yes" ?>
        <T3FlexForms>
            <data>
                <sheet index="sDEF">
                    <language index="lDEF">
                        <field index="settings.items">
                            <el index="1">
                                <container>
                                    <el>
                                        <text>
                                            <value index="vDEF">contact editor@example.com</value>
                                        </text>
                                    </el>
                                </container>
                            </el>
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

        // v13 declares `ds` as a map keyed by the pointer field value, v14 as the
        // data structure string itself; follow whichever shape the running core
        // uses so the identifier resolves through its own code path.
        $config = &$GLOBALS['TCA']['tt_content']['columns']['pi_flexform']['config'];
        $config['ds'] = is_array($config['ds'] ?? null) ? ['default' => self::DATA_STRUCTURE] : self::DATA_STRUCTURE;
        unset($config);
        // v14 resolves the data structure through the schema, which was built at
        // boot from the unmodified TCA.
        $this->get(TcaSchemaFactory::class)->rebuild($GLOBALS['TCA']);

        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $connection->insert('tt_content', ['uid' => 1, 'pid' => 1, 'CType' => 'list', 'header' => 'With FlexForm', 'pi_flexform' => self::STORED_FLEXFORM]);
        $connection->insert('tt_content', ['uid' => 2, 'pid' => 1, 'CType' => 'text', 'header' => 'Without FlexForm', 'pi_flexform' => '']);
        $connection->insert('tt_content', ['uid' => 3, 'pid' => 1, 'CType' => 'list', 'header' => 'With a section', 'pi_flexform' => self::STORED_SECTION]);
    }

    #[Test]
    public function reportsAStoredValueThatTheCurrentDataStructureNoLongerDeclares(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', 'uid' => '1']);

        self::assertSame(0, $exitCode);
        self::assertTrue($result['hasFlexForm']);
        self::assertTrue($result['dataStructureResolved']);
        self::assertSame('pi_flexform', $result['field']);

        // The rename: stored under the old key, declared under the new one.
        self::assertSame(1, $result['orphanedCount']);
        self::assertSame(['sDEF/settings.limit' => '9'], $result['orphaned']);
        self::assertSame(1, $result['missingCount']);
        // Straight from the data structure XML, so a string.
        self::assertSame('6', ((array) ((array) $result['missing'])['sDEF/settings.maxItems'])['default']);

        // A field that exists in both is neither orphaned nor missing.
        self::assertSame(['sDEF/settings.plain' => 'kept'], $result['matched']);
        self::assertArrayHasKey('_hint', $result);
    }

    #[Test]
    public function saysARecordHasNoFlexFormInsteadOfReturningAnEmptyStructure(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', 'uid' => '2']);

        self::assertSame(0, $exitCode);
        self::assertFalse($result['hasFlexForm']);
        self::assertArrayNotHasKey('orphaned', $result);
        self::assertStringContainsString('no FlexForm', (string) $result['_hint']);
    }

    #[Test]
    public function reportsASectionAsOmittedInsteadOfDumpingItsContents(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', 'uid' => '3']);

        self::assertSame(0, $exitCode);
        self::assertTrue($result['dataStructureResolved']);

        // The section is stored but not declared, so it is orphaned like any other
        // key. Its contents are not descended into, which means neither the value
        // cap nor the redaction applies to them, so they must not be passed through.
        $orphaned = (array) $result['orphaned'];
        self::assertArrayHasKey('sDEF/settings.items', $orphaned);
        self::assertIsString($orphaned['sDEF/settings.items']);
        self::assertStringNotContainsString('editor@example.com', (string) json_encode($result));
    }

    #[Test]
    public function answersAFieldThatIsNotAFlexFormColumnWithTheOnesThatAre(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', 'uid' => '1', '--field' => 'header']);

        self::assertSame(0, $exitCode);
        self::assertFalse($result['flexField']);
        self::assertContains('pi_flexform', (array) $result['flexFields']);
    }

    #[Test]
    public function answersAMissingRecordRatherThanFailing(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content', 'uid' => '999']);

        self::assertSame(0, $exitCode);
        self::assertFalse($result['recordFound']);
    }

    #[Test]
    public function answersATableWithoutAnyFlexFormColumn(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'sys_template', 'uid' => '1']);

        self::assertSame(0, $exitCode);
        self::assertSame([], $result['flexFields']);
        self::assertStringContainsString('no column of TCA type "flex"', (string) $result['_hint']);
    }

    #[Test]
    public function refusesSessionStorage(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'be_sessions', 'uid' => '1']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('blocked', (string) $result['error']);
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:flexform:diff');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
