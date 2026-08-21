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
 * IconsCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class IconsCommandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    #[Test]
    public function reportsTheIdentifierCountGroupedByLeadingSegmentWithoutArguments(): void
    {
        [$exitCode, $result] = $this->runCommand([]);

        self::assertSame(0, $exitCode);
        self::assertGreaterThan(0, $result['count']);
        $groups = (array) $result['groups'];
        self::assertArrayHasKey('actions', $groups);
        self::assertGreaterThan(0, $groups['actions']);
        self::assertArrayHasKey('_hint', $result);
    }

    #[Test]
    public function confirmsARegisteredIdentifierAndNamesTheProvidingExtension(): void
    {
        [$exitCode, $result] = $this->runCommand(['--identifiers' => 'actions-add']);

        self::assertSame(0, $exitCode);
        self::assertSame(1, $result['checked']);
        $icon = (array) ((array) $result['identifiers'])['actions-add'];
        self::assertTrue($icon['registered']);
        self::assertSame('core', $icon['providedBy']);
        self::assertStringContainsString('IconProvider', (string) $icon['provider']);
    }

    #[Test]
    public function answersAnUnregisteredIdentifierWithSuggestions(): void
    {
        [$exitCode, $result] = $this->runCommand(['--identifiers' => 'actions-add,action-ad']);

        self::assertSame(0, $exitCode);
        $identifiers = (array) $result['identifiers'];

        self::assertTrue(((array) $identifiers['actions-add'])['registered']);

        $miss = (array) $identifiers['action-ad'];
        self::assertFalse($miss['registered']);
        self::assertContains('actions-add', (array) $miss['suggestions']);
        self::assertStringContainsString('not registered', (string) $miss['_hint']);
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:icons:lookup');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
