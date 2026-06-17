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

use KonradMichalik\Typo3AiMate\Command\PageInfoCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PageInfoCommandTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final class PageInfoCommandTest extends TestCase
{
    #[Test]
    public function matchUserIntPluginsDetectsAListPluginSignature(): void
    {
        $setup = ['tt_content.' => ['list.' => ['20.' => ['news_pi1' => 'USER_INT']]]];
        $contentElements = [
            ['CType' => 'list', 'plugin' => 'news_pi1'],
            ['CType' => 'text', 'plugin' => null],
        ];

        self::assertSame(['news_pi1'], PageInfoCommand::matchUserIntPlugins($setup, $contentElements));
    }

    #[Test]
    public function matchUserIntPluginsDetectsACTypeRenderedAsUserInt(): void
    {
        $setup = ['tt_content.' => ['my_element' => 'USER_INT']];
        $contentElements = [['CType' => 'my_element', 'plugin' => null]];

        self::assertSame(['my_element'], PageInfoCommand::matchUserIntPlugins($setup, $contentElements));
    }

    #[Test]
    public function matchUserIntPluginsDeduplicatesRepeatedSignatures(): void
    {
        $setup = ['tt_content.' => ['list.' => ['20.' => ['news_pi1' => 'USER_INT']]]];
        $contentElements = [
            ['CType' => 'list', 'plugin' => 'news_pi1'],
            ['CType' => 'list', 'plugin' => 'news_pi1'],
        ];

        self::assertSame(['news_pi1'], PageInfoCommand::matchUserIntPlugins($setup, $contentElements));
    }

    #[Test]
    public function matchUserIntPluginsReturnsEmptyWhenNothingIsUncached(): void
    {
        $setup = ['tt_content.' => ['list.' => ['20.' => ['news_pi1' => 'USER']]]];
        $contentElements = [['CType' => 'list', 'plugin' => 'news_pi1']];

        self::assertSame([], PageInfoCommand::matchUserIntPlugins($setup, $contentElements));
    }

    #[Test]
    public function matchUserIntPluginsToleratesAbsentTypoScriptStructures(): void
    {
        self::assertSame([], PageInfoCommand::matchUserIntPlugins([], [['CType' => 'text', 'plugin' => null]]));
    }
}
