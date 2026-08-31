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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command;

use KonradMichalik\Typo3AiMate\Command\FluidNamespacesCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Fluid\Core\ViewHelper\{ViewHelperResolver, ViewHelperResolverFactoryInterface};

/**
 * FluidNamespacesCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class FluidNamespacesCommandTest extends TestCase
{
    #[Test]
    public function reportsAPrefixMappedToNullAsAnEmptyList(): void
    {
        // Fluid maps a prefix to null to ignore it (xmlns:xsi is the usual case).
        // That is an answer worth reporting as such, not a prefix to drop.
        $command = $this->command(['f' => ['TYPO3Fluid\\Fluid\\ViewHelpers'], 'xsi' => null]);

        self::assertSame([
            'f' => ['TYPO3Fluid\\Fluid\\ViewHelpers'],
            'xsi' => [],
        ], $command->describeNamespaces());
    }

    #[Test]
    public function sortsPrefixesAlphabetically(): void
    {
        $command = $this->command(['z' => ['Z\\ViewHelpers'], 'a' => ['A\\ViewHelpers']]);

        self::assertSame(['a', 'z'], array_keys($command->describeNamespaces()));
    }

    /**
     * @param array<string, list<string>|null> $namespaces
     */
    private function command(array $namespaces): FluidNamespacesCommand
    {
        $resolver = self::createStub(ViewHelperResolver::class);
        $resolver->method('getNamespaces')->willReturn($namespaces);

        $factory = self::createStub(ViewHelperResolverFactoryInterface::class);
        $factory->method('create')->willReturn($resolver);

        return new FluidNamespacesCommand($factory);
    }
}
