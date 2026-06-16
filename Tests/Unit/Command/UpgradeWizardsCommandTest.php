<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <km@move-elevator.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command;

use KonradMichalik\Typo3AiMate\Command\UpgradeWizardsCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Core\BootService;

/**
 * UpgradeWizardsCommandTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
final class UpgradeWizardsCommandTest extends TestCase
{
    private UpgradeWizardsCommand $command;

    protected function setUp(): void
    {
        $this->command = new UpgradeWizardsCommand(self::createStub(BootService::class));
    }

    #[Test]
    public function formatWizardMapsAvailableWizardInformation(): void
    {
        $entry = $this->command->formatWizard('myWizard', [
            'title' => 'Migrate foo',
            'explanation' => 'Moves foo to bar.',
            'shouldRenderWizard' => true,
        ], false);

        self::assertSame([
            'identifier' => 'myWizard',
            'title' => 'Migrate foo',
            'description' => 'Moves foo to bar.',
            'status' => 'AVAILABLE',
            'updateNecessary' => true,
        ], $entry);
    }

    #[Test]
    public function formatWizardMarksDoneWizardsAndDefaultsMissingFields(): void
    {
        $entry = $this->command->formatWizard('doneWizard', [], true);

        self::assertSame('DONE', $entry['status']);
        self::assertSame('', $entry['title']);
        self::assertSame('', $entry['description']);
        self::assertFalse($entry['updateNecessary']);
    }
}
