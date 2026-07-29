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

use KonradMichalik\Typo3AiMate\Command\TsConfigCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * TsConfigCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class TsConfigCommandTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__.'/../Fixtures/pages_tsconfig.csv');
        $this->importCSVDataSet(__DIR__.'/../Fixtures/be_users_tsconfig.csv');
    }

    #[Test]
    public function dumpsPageTsConfigScopedToAPath(): void
    {
        [$exitCode, $result] = $this->runCommand(['pageId' => '1', '--path' => 'tx_aimatetest']);

        self::assertSame(0, $exitCode);
        self::assertSame(['foo' => 'bar'], $result);
    }

    #[Test]
    public function returnsTopLevelOverviewByDefault(): void
    {
        [$exitCode, $result] = $this->runCommand(['pageId' => '1']);

        self::assertSame(0, $exitCode);
        self::assertArrayHasKey('_hint', $result);
        self::assertArrayHasKey('tx_aimatetest.', $result);
    }

    #[Test]
    public function dumpsTheFullResolvedTreeOnRequest(): void
    {
        [$exitCode, $result] = $this->runCommand(['pageId' => '1', '--full' => true]);

        self::assertSame(0, $exitCode);
        self::assertSame('bar', $result['tx_aimatetest.']['foo']);
    }

    #[Test]
    public function dumpsUserTsConfigForABackendUser(): void
    {
        [$exitCode, $result] = $this->runCommand(['pageId' => '1', '--type' => 'user', '--user' => '1', '--path' => 'tx_aimatetest']);

        self::assertSame(0, $exitCode);
        self::assertSame(['user' => 'one'], $result);
    }

    #[Test]
    public function failsWithAReadableErrorForAnUnknownBackendUser(): void
    {
        [$exitCode, $result] = $this->runCommand(['pageId' => '1', '--type' => 'user', '--user' => '42']);

        self::assertSame(1, $exitCode);
        self::assertSame('Backend user with UID 42 not found.', $result['error']);
    }

    /**
     * @param array<string, string|bool> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $tester = new CommandTester(new TsConfigCommand());
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
