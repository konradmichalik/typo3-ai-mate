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

use function count;

/**
 * BackendModulesCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class BackendModulesCommandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'install',
        'backend',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    #[Test]
    public function reportsEveryRegisteredModuleWithItsParentAndResolvedNavigationComponent(): void
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:backend:modules');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);

        $modules = (array) $result['modules'];
        self::assertSame(count($modules), $result['count']);
        self::assertArrayHasKey('_hint', $result);

        self::assertGreaterThan(5, $result['count']);
        foreach ($modules as $identifier => $module) {
            self::assertIsArray($module);
            self::assertStringStartsWith('/module', (string) $module['path'], $identifier.' reports a route path');
        }

        // Submodules report the parent they hang off.
        self::assertNotEmpty(array_filter($modules, static fn (mixed $module): bool => isset(((array) $module)['parent'])));

        // Sorted by identifier, so two responses can be compared.
        $identifiers = array_keys($modules);
        $sorted = $identifiers;
        sort($sorted);
        self::assertSame($sorted, $identifiers);
    }

    #[Test]
    public function aSubmoduleReportsTheNavigationComponentItInheritsFromItsParent(): void
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:backend:modules');
        $tester = new CommandTester($command);
        $tester->execute([]);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        $modules = (array) $result['modules'];

        $inherited = [];
        foreach ($modules as $identifier => $module) {
            $module = (array) $module;
            $parent = (string) ($module['parent'] ?? '');
            $component = (string) ($module['navigationComponent'] ?? '');
            if ('' === $parent || '' === $component || !isset($modules[$parent])) {
                continue;
            }
            if ($component === (string) (((array) $modules[$parent])['navigationComponent'] ?? '')) {
                $inherited[] = $identifier;
            }
        }

        // Core registers submodules with inheritNavigationComponent; the value
        // reported here is the resolved one, which their own configuration file
        // does not carry.
        self::assertNotEmpty($inherited, 'At least one submodule reports its parent\'s navigation component.');
    }
}
