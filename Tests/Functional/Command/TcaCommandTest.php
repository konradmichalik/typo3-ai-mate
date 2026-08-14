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
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * TcaCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class TcaCommandTest extends FunctionalTestCase
{
    // EXT:install provides LateBootService (autowired by UpgradeWizardsCommand),
    // which the extension's service definitions require to compile.
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    #[Test]
    public function listsAllTableNamesSortedWhenNoTableGiven(): void
    {
        [$exitCode, $result] = $this->runCommand([]);

        self::assertSame(0, $exitCode);
        self::assertIsArray($result);
        self::assertContains('pages', $result);
        self::assertContains('tt_content', $result);
        self::assertSame($result, array_values($result));
        $sorted = $result;
        sort($sorted);
        self::assertSame($sorted, $result);
    }

    #[Test]
    public function dumpsCapabilitiesRecordTypesRelationsAndColumnsForTtContent(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tt_content']);

        self::assertSame(0, $exitCode);

        $ctrl = $result['ctrl'];
        self::assertIsArray($ctrl);
        self::assertSame('CType', $ctrl['type']);
        self::assertSame('sorting', $ctrl['sortby']);

        $capabilities = $result['capabilities'];
        self::assertIsArray($capabilities);
        self::assertSame('deleted', $capabilities['softDelete']);
        self::assertTrue($capabilities['workspace']);
        self::assertTrue($capabilities['language']);
        self::assertSame('sorting', $capabilities['sorting']);

        $recordTypes = $result['recordTypes'];
        self::assertIsArray($recordTypes);
        self::assertNotSame([], $recordTypes);
        self::assertArrayHasKey('text', $recordTypes);
        self::assertContains('bodytext', $recordTypes['text']);

        $relations = $result['relations'];
        self::assertIsArray($relations);
        self::assertArrayHasKey('image', $relations);
        $imageRelation = $relations['image'];
        self::assertIsArray($imageRelation);
        self::assertSame('1:n', $imageRelation['type']);
        self::assertSame(['sys_file_reference'], $imageRelation['toTables']);

        $columns = $result['columns'];
        self::assertIsArray($columns);
        self::assertArrayHasKey('header', $columns);
        $header = $columns['header'];
        self::assertIsArray($header);
        self::assertSame('input', $header['type']);
    }

    #[Test]
    public function failsForAnUnknownTable(): void
    {
        [$exitCode, $result] = $this->runCommand(['table' => 'tx_does_not_exist']);

        self::assertSame(1, $exitCode);
        self::assertSame('Unknown TCA table "tx_does_not_exist".', $result['error']);
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:tca:dump');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
