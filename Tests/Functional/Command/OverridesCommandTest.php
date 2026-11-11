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
 * OverridesCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class OverridesCommandTest extends FunctionalTestCase
{
    private const ORIGINAL = 'TYPO3\CMS\Core\Utility\File\BasicFileUtility';
    private const REPLACEMENT = 'KonradMichalik\OverridesFixture\Xclass\BasicFileUtility';

    protected bool $initializeDatabase = false;

    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
        __DIR__.'/../Fixtures/Extensions/overrides_fixture',
    ];

    #[Test]
    public function listsTheXclassAnExtensionRegisteredInItsExtLocalconf(): void
    {
        [$exitCode, $result] = $this->runCommand([]);

        $fixture = array_values(array_filter($result['xclasses'], static fn (array $xclass): bool => self::ORIGINAL === $xclass['original']));

        self::assertSame(0, $exitCode);
        self::assertSame([[
            'original' => self::ORIGINAL,
            'replacement' => self::REPLACEMENT,
            'effectiveClass' => self::REPLACEMENT,
            'extension' => 'overrides_fixture',
            'package' => 'konradmichalik/overrides-fixture',
            'ownerDeterminedBy' => 'classFile',
        ]], $fixture);
    }

    #[Test]
    public function answersForTheOriginalAndForTheReplacementClass(): void
    {
        [, $byOriginal] = $this->runCommand(['--class' => self::ORIGINAL]);
        [, $byReplacement] = $this->runCommand(['--class' => self::REPLACEMENT]);

        self::assertTrue($byOriginal['found']);
        self::assertSame([self::REPLACEMENT], array_column($byOriginal['xclasses'], 'replacement'));
        self::assertSame($byOriginal['xclasses'], $byReplacement['xclasses']);
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:overrides:list');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
