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
use Symfony\Component\Console\Application;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function sprintf;
use function str_starts_with;

/**
 * CommandDefinitionsTest.
 *
 * Every command of this extension is reached through the real console, where
 * the application's own options are merged into the command's definition. A
 * name that exists on both sides makes the command unusable, and a CommandTester
 * never notices because it skips that merge.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class CommandDefinitionsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    #[Test]
    public function noOptionCollidesWithTheOnesTheConsoleApplicationReserves(): void
    {
        $registry = $this->get(CommandRegistry::class);
        $reserved = (new Application())->getDefinition();
        $checked = 0;

        foreach ($registry->getNames() as $name) {
            if (!str_starts_with($name, 'typo3-ai-mate:')) {
                continue;
            }

            ++$checked;
            foreach ($registry->get($name)->getDefinition()->getOptions() as $option) {
                self::assertFalse(
                    $reserved->hasOption($option->getName()),
                    sprintf(
                        '"%s" defines --%s, which the console application already reserves: the command then fails with '
                        .'"An option named \'%s\' already exists" when called without it, and is swallowed by the '
                        .'application when called with it.',
                        $name,
                        $option->getName(),
                        $option->getName(),
                    ),
                );
            }
        }

        self::assertGreaterThan(0, $checked, 'No command of this extension was registered.');
    }
}
