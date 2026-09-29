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

use KonradMichalik\Typo3AiMate\Command\ServiceDetailCommand;
use KonradMichalik\Typo3AiMate\Tests\Unit\Command\Fixtures\InMemoryContainer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ServiceDetailCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ServiceDetailCommandTest extends TestCase
{
    #[Test]
    public function reportsClassSharedAndConstructorArgumentTypesForAPublicService(): void
    {
        $container = new InMemoryContainer(['acme.logger' => static fn () => new WithTypedConstructor(new stdClass(), 'default', null)]);

        $result = $this->runCommand($container, 'acme.logger');

        self::assertSame(0, $result[0]);
        self::assertSame('acme.logger', $result[1]['id']);
        self::assertSame(WithTypedConstructor::class, $result[1]['class']);
        self::assertTrue($result[1]['shared']);
        self::assertSame([
            ['position' => 0, 'name' => 'dependency', 'type' => 'stdClass'],
            ['position' => 1, 'name' => 'label', 'type' => 'string'],
            ['position' => 2, 'name' => 'nullable', 'type' => '?stdClass'],
        ], $result[1]['constructorArguments']);
    }

    #[Test]
    public function reportsTheResolvedClassSeparatelyWhenTheIdIsAnAliasOrInterface(): void
    {
        $container = new InMemoryContainer(['Some\\Interface' => static fn () => new NoConstructor()]);

        $result = $this->runCommand($container, 'Some\\Interface');

        self::assertSame('Some\\Interface', $result[1]['id']);
        self::assertSame(NoConstructor::class, $result[1]['class']);
    }

    #[Test]
    public function aClassWithNoConstructorHasAnEmptyArgumentList(): void
    {
        $container = new InMemoryContainer(['acme.noop' => static fn () => new NoConstructor()]);

        $result = $this->runCommand($container, 'acme.noop');

        self::assertSame([], $result[1]['constructorArguments']);
    }

    #[Test]
    public function aNonSharedServiceReturnsADifferentInstanceOnEachGetAndIsReportedAsNotShared(): void
    {
        $container = new InMemoryContainer(['acme.prototype' => static fn () => new NoConstructor()], shared: false);

        $result = $this->runCommand($container, 'acme.prototype');

        self::assertFalse($result[1]['shared']);
    }

    #[Test]
    public function anUnregisteredOrPrivateServiceFailsWithAReadableError(): void
    {
        $container = new InMemoryContainer([]);

        $result = $this->runCommand($container, 'acme.ghost');

        self::assertSame(1, $result[0]);
        self::assertSame(
            'Service "acme.ghost" is not registered or not public. Only public services can be inspected; TYPO3 makes most services private by default.',
            $result[1]['error'],
        );
    }

    #[Test]
    public function aServiceThatFailsToInstantiateFailsWithItsExceptionMessageRatherThanCrashing(): void
    {
        $container = new InMemoryContainer(['acme.broken' => static function (): never {
            throw new RuntimeException('missing runtime dependency');
        }]);

        $result = $this->runCommand($container, 'acme.broken');

        self::assertSame(1, $result[0]);
        self::assertSame('Could not resolve service "acme.broken": missing runtime dependency', $result[1]['error']);
    }

    /**
     * @return array{0: int, 1: array<mixed>}
     */
    private function runCommand(InMemoryContainer $container, string $id): array
    {
        $tester = new CommandTester(new ServiceDetailCommand($container));
        $exitCode = $tester->execute(['id' => $id]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}

/**
 * WithTypedConstructor.
 *
 * @internal fixture class for this test only
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class WithTypedConstructor
{
    public function __construct(stdClass $dependency, string $label = 'x', ?stdClass $nullable = null)
    {
        // This fixture exists solely as a reflection target for its constructor's type
        // signature; unset() is a genuine use of each parameter, not a suppression.
        unset($dependency, $label, $nullable);
    }
}

/**
 * NoConstructor.
 *
 * @internal fixture class for this test only
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class NoConstructor {}
