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
 * ViewHelpersCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ViewHelpersCommandTest extends FunctionalTestCase
{
    protected bool $initializeDatabase = false;

    protected array $coreExtensionsToLoad = [
        'install',
        'fluid',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    #[Test]
    public function listsTheGlobalPrefixesWhenCalledWithoutArguments(): void
    {
        [$exitCode, $result] = $this->runCommand([]);

        self::assertSame(0, $exitCode);
        self::assertContains('f', (array) $result['namespaces']);
        self::assertStringContainsString('names', (string) $result['_hint']);
    }

    #[Test]
    public function returnsAnExistingViewHelperWithItsArgumentsAndRequiredFlags(): void
    {
        [$exitCode, $result] = $this->runCommand(['--names' => 'f:link.external']);

        self::assertSame(0, $exitCode);
        self::assertSame(1, $result['checked']);
        $viewHelper = (array) ((array) $result['viewHelpers'])['f:link.external'];
        self::assertTrue($viewHelper['exists']);
        self::assertSame('TYPO3\\CMS\\Fluid\\ViewHelpers\\Link\\ExternalViewHelper', $viewHelper['class']);

        $arguments = (array) $viewHelper['arguments'];
        $uri = (array) $arguments['uri'];
        self::assertSame('string', $uri['type']);
        self::assertTrue($uri['required']);
        self::assertFalse(((array) $arguments['defaultScheme'])['required']);
        self::assertTrue($viewHelper['acceptsUndeclaredArguments']);
    }

    #[Test]
    public function answersAnUnknownViewHelperWithTheClosestNamesOfItsNamespace(): void
    {
        [$exitCode, $result] = $this->runCommand(['--names' => 'f:link.page,f:link.pag']);

        self::assertSame(0, $exitCode);
        $viewHelpers = (array) $result['viewHelpers'];
        self::assertTrue(((array) $viewHelpers['f:link.page'])['exists']);

        $miss = (array) $viewHelpers['f:link.pag'];
        self::assertFalse($miss['exists']);
        self::assertContains('f:link.page', (array) $miss['suggestions']);
        self::assertArrayHasKey('_hint', $miss);
    }

    #[Test]
    public function answersAnUnknownNamespaceWithTheClosestGlobalPrefixes(): void
    {
        [$exitCode, $result] = $this->runCommand(['--names' => 'fx:link.page']);

        self::assertSame(0, $exitCode);
        $miss = (array) ((array) $result['viewHelpers'])['fx:link.page'];
        self::assertFalse($miss['exists']);
        self::assertContains('f', (array) $miss['suggestions']);
        self::assertStringContainsString('xmlns', (string) $miss['_hint']);
    }

    #[Test]
    public function marksAMalformedNameAsInvalid(): void
    {
        [$exitCode, $result] = $this->runCommand(['--names' => 'link.page']);

        self::assertSame(0, $exitCode);
        self::assertFalse(((array) ((array) $result['viewHelpers'])['link.page'])['valid']);
    }

    #[Test]
    public function listsTheViewHelperNamesOfANamespace(): void
    {
        [$exitCode, $result] = $this->runCommand(['--namespace' => 'f']);

        self::assertSame(0, $exitCode);
        self::assertSame('f', $result['namespace']);
        $names = (array) $result['viewHelpers'];
        self::assertSame($result['count'], count($names));
        // Both PHP namespaces behind "f" contribute: Fluid's own and TYPO3's.
        self::assertContains('if', $names);
        self::assertContains('link.page', $names);
        self::assertNotContains('abstractCondition', $names);
    }

    #[Test]
    public function answersAnUnknownNamespaceListingWithSuggestions(): void
    {
        [$exitCode, $result] = $this->runCommand(['--namespace' => 'fx']);

        self::assertSame(0, $exitCode);
        self::assertFalse($result['exists']);
        self::assertContains('f', (array) $result['suggestions']);
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:fluid:viewhelpers');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
