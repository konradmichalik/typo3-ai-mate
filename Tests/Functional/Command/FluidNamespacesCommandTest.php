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
 * FluidNamespacesCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class FluidNamespacesCommandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'install',
        'fluid',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    #[Test]
    public function reportsTheGloballyAvailablePrefixesWithTheirPhpNamespaces(): void
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:fluid:namespaces');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);

        $namespaces = (array) $result['namespaces'];
        self::assertGreaterThan(0, $result['count']);
        self::assertSame(count($namespaces), $result['count']);

        // "f" is Fluid's own prefix and is always available without a declaration.
        self::assertArrayHasKey('f', $namespaces);
        self::assertContains('TYPO3Fluid\\Fluid\\ViewHelpers', (array) $namespaces['f']);
        self::assertArrayHasKey('_hint', $result);
    }
}
