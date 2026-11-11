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
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * BePermissionsCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class BePermissionsCommandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Fixtures/be_permissions.csv');
    }

    #[Test]
    public function mergesThePermissionsOfNestedGroups(): void
    {
        [$exitCode, $result] = $this->runCommand(['--user' => '2']);

        self::assertSame(0, $exitCode);
        self::assertSame(['user' => 2], $result['subject']);
        self::assertFalse($result['admin']);
        self::assertSame([['uid' => 1, 'title' => 'Base editors'], ['uid' => 2, 'title' => 'Site editors']], $result['groups']);
        self::assertEqualsCanonicalizing(['pages', 'tt_content'], $result['tables']['select']);
        self::assertEqualsCanonicalizing(['pages', 'tt_content'], $result['tables']['modify']);
        self::assertEqualsCanonicalizing(['tt_content:hidden', 'pages:hidden'], $result['nonExcludeFields']);
        self::assertSame(['tt_content:CType:text'], $result['explicitAllowDeny']);
        self::assertSame(['restricted' => true, 'allowed' => [0, 1]], $result['languages']);
        self::assertSame([1], $result['webmounts']);
        self::assertSame([1, 2], $result['filemounts']);
    }

    #[Test]
    public function reportsWebmountAndEffectivePagePermissionBits(): void
    {
        [, $root] = $this->runCommand(['--user' => '2', '--page-id' => '1']);
        [, $section] = $this->runCommand(['--user' => '2', '--page-id' => '2']);
        [, $outside] = $this->runCommand(['--user' => '2', '--page-id' => '3']);

        self::assertSame(['uid' => 1, 'found' => true, 'inWebmount' => true, 'webmount' => 1, 'permissions' => 27, 'granted' => ['show', 'edit', 'new', 'editContent']], $root['page']);
        self::assertSame(['uid' => 2, 'found' => true, 'inWebmount' => true, 'webmount' => 1, 'permissions' => 1, 'granted' => ['show']], $section['page']);
        self::assertSame(['uid' => 3, 'found' => true, 'inWebmount' => false, 'webmount' => null, 'permissions' => 0, 'granted' => []], $outside['page']);
    }

    #[Test]
    public function reportsAPageThatDoesNotExist(): void
    {
        [$exitCode, $result] = $this->runCommand(['--user' => '2', '--page-id' => '99']);

        self::assertSame(0, $exitCode);
        self::assertSame(['uid' => 99, 'found' => false], $result['page']);
    }

    #[Test]
    public function splitsTheFieldsOfATableIntoEditableExcludedAndDisabledByTsconfig(): void
    {
        [$exitCode, $result] = $this->runCommand(['--user' => '2', '--table' => 'tt_content', '--page-id' => '1']);

        self::assertSame(0, $exitCode);
        $table = $result['table'];
        self::assertSame('tt_content', $table['name']);
        self::assertTrue($table['select']);
        self::assertTrue($table['modify']);
        self::assertContains('header', $table['editableFields']);
        self::assertContains('hidden', $table['editableFields']);
        self::assertContains('starttime', $table['excludedFields']);
        self::assertNotContains('hidden', $table['excludedFields']);
        self::assertContains('header_layout', $table['disabledByTsconfig'], 'user TSconfig page.TCEFORM override');
        self::assertContains('subheader', $table['disabledByTsconfig'], 'page TSconfig TCEFORM');
        self::assertNotContains('header_layout', $table['editableFields']);
        self::assertNotContains('starttime', $table['editableFields']);
        self::assertSame(['tt_content:hidden'], $result['nonExcludeFields'], 'narrowed to the requested table');
    }

    #[Test]
    public function aTableTheUserCannotModifyHasNoEditableField(): void
    {
        [$exitCode, $result] = $this->runCommand(['--user' => '2', '--table' => 'sys_category']);

        self::assertSame(0, $exitCode);
        self::assertFalse($result['table']['modify']);
        self::assertSame([], $result['table']['editableFields']);
    }

    #[Test]
    public function failsForAnUnknownTable(): void
    {
        [$exitCode, $result] = $this->runCommand(['--user' => '2', '--table' => 'tx_does_not_exist']);

        self::assertSame(1, $exitCode);
        self::assertSame('Unknown TCA table "tx_does_not_exist".', $result['error']);
    }

    #[Test]
    public function evaluatesAGroupAsItsOnlyMember(): void
    {
        [$exitCode, $result] = $this->runCommand(['--group' => '2', '--page-id' => '1']);

        self::assertSame(0, $exitCode);
        self::assertSame(['group' => 2], $result['subject']);
        self::assertSame([1, 2], array_column($result['groups'], 'uid'));
        self::assertSame([1], $result['webmounts']);
        self::assertSame(27, $result['page']['permissions']);
    }

    #[Test]
    public function reportsAdminUsersExplicitly(): void
    {
        [$exitCode, $result] = $this->runCommand(['--user' => '1', '--table' => 'tt_content', '--page-id' => '3']);

        self::assertSame(0, $exitCode);
        self::assertTrue($result['admin']);
        self::assertSame(['user' => 1], $result['subject']);
        self::assertStringContainsString('Admin', (string) $result['_hint']);
        self::assertArrayNotHasKey('tables', $result);
    }

    #[Test]
    public function neverOutputsPersonalFieldsOfTheUser(): void
    {
        $tester = $this->tester();
        $tester->execute(['--user' => '2', '--table' => 'tt_content', '--page-id' => '1']);
        $display = $tester->getDisplay();

        foreach (['editor-fixture', 'Erika Fixture', 'erika@example.com', 'secrethash', '"username"', '"realName"', '"email"', '"password"'] as $personal) {
            self::assertStringNotContainsString($personal, $display);
        }
    }

    #[Test]
    public function leavesTheUserRecordAndTheSystemLogUntouched(): void
    {
        $this->runCommand(['--user' => '2']);

        $connectionPool = $this->get(ConnectionPool::class);
        $user = $connectionPool->getConnectionForTable('be_users')->select(['workspace_id'], 'be_users', ['uid' => 2])->fetchAssociative();
        self::assertSame(7, (int) ($user['workspace_id'] ?? 0), 'the stored workspace must not be rewritten');
        self::assertSame(0, $connectionPool->getConnectionForTable('sys_log')->count('*', 'sys_log', []));
    }

    #[Test]
    public function failsWithoutAUserOrGroup(): void
    {
        [$exitCode, $result] = $this->runCommand([]);

        self::assertSame(1, $exitCode);
        self::assertSame('Pass exactly one of --user <uid> or --group <uid>.', $result['error']);
    }

    #[Test]
    public function failsForBothAUserAndAGroup(): void
    {
        [$exitCode, $result] = $this->runCommand(['--user' => '2', '--group' => '1']);

        self::assertSame(1, $exitCode);
        self::assertSame('Pass exactly one of --user <uid> or --group <uid>.', $result['error']);
    }

    #[Test]
    public function failsForAnUnknownUser(): void
    {
        [$exitCode, $result] = $this->runCommand(['--user' => '42']);

        self::assertSame(1, $exitCode);
        self::assertSame('Backend user with UID 42 not found or disabled.', $result['error']);
    }

    #[Test]
    public function failsForAnUnknownGroup(): void
    {
        [$exitCode, $result] = $this->runCommand(['--group' => '42']);

        self::assertSame(1, $exitCode);
        self::assertSame('Backend group with UID 42 not found or hidden.', $result['error']);
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $tester = $this->tester();
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }

    private function tester(): CommandTester
    {
        return new CommandTester($this->get(CommandRegistry::class)->get('typo3-ai-mate:be-permissions'));
    }
}
