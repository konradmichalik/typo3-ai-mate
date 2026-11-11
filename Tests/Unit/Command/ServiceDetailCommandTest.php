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

use KonradMichalik\Ttt\Attribute\WithEnvironment;
use KonradMichalik\Typo3AiMate\Command\ServiceDetailCommand;
use KonradMichalik\Typo3AiMate\Service\ServiceTagMap;
use KonradMichalik\Typo3AiMate\Tests\Unit\Command\Fixtures\InMemoryContainer;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
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
#[WithEnvironment]
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
            throw new RuntimeException('missing runtime dependency', 5241347027);
        }]);

        $result = $this->runCommand($container, 'acme.broken');

        self::assertSame(1, $result[0]);
        self::assertSame('Could not resolve service "acme.broken": missing runtime dependency', $result[1]['error']);
    }

    #[Test]
    public function aTagLookupListsTaggedServicesFromTheMapWithoutTouchingTheContainer(): void
    {
        $map = new ServiceTagMap(['acme.tag' => [
            ['id' => 'acme.private', 'class' => NoConstructor::class, 'public' => false, 'attributes' => [['priority' => 10]]],
        ]]);

        $result = $this->runTagCommand($map, ['--tag' => 'acme.tag']);

        self::assertSame(0, $result[0]);
        self::assertSame([
            'tag' => 'acme.tag',
            'services' => [
                ['id' => 'acme.private', 'class' => NoConstructor::class, 'public' => false, 'attributes' => [['priority' => 10]]],
            ],
            'serviceCount' => 1,
            '_truncated' => false,
        ], $result[1]);
    }

    #[Test]
    public function anUnknownTagAnswersAnEmptyListWithTheKnownTagNames(): void
    {
        $map = new ServiceTagMap(['acme.one' => [], 'acme.two' => []]);

        $result = $this->runTagCommand($map, ['--tag' => 'acme.ghost']);

        self::assertSame(0, $result[0]);
        self::assertSame([], $result[1]['services']);
        self::assertSame(['acme.one', 'acme.two'], $result[1]['knownTags']);
    }

    #[Test]
    public function aTagWithManyServicesIsTruncated(): void
    {
        $services = [];
        for ($i = 0; $i < 201; ++$i) {
            $services[] = ['id' => 'acme.'.$i, 'class' => NoConstructor::class, 'public' => false, 'attributes' => [[]]];
        }

        $result = $this->runTagCommand(new ServiceTagMap(['acme.tag' => $services]), ['--tag' => 'acme.tag']);

        self::assertIsArray($result[1]['services']);
        self::assertCount(200, $result[1]['services']);
        self::assertSame(201, $result[1]['serviceCount']);
        self::assertTrue($result[1]['_truncated']);
    }

    /**
     * @param array<string, string> $input
     */
    #[Test]
    #[DataProvider('ambiguousInputs')]
    public function exactlyOneOfIdAndTagIsRequired(array $input): void
    {
        $result = $this->runTagCommand(new ServiceTagMap(), $input);

        self::assertSame(1, $result[0]);
        self::assertSame('Pass either a service id or --tag, not both and not neither.', $result[1]['error']);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function ambiguousInputs(): iterable
    {
        yield 'neither' => [[]];
        yield 'both' => [['id' => 'acme.logger', '--tag' => 'acme.tag']];
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<mixed>}
     */
    private function runTagCommand(ServiceTagMap $map, array $input): array
    {
        $tester = new CommandTester(new ServiceDetailCommand(new InMemoryContainer([]), $map));
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }

    /**
     * @return array{0: int, 1: array<mixed>}
     */
    private function runCommand(InMemoryContainer $container, string $id): array
    {
        $tester = new CommandTester(new ServiceDetailCommand($container, new ServiceTagMap()));
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
final readonly class WithTypedConstructor
{
    public function __construct(
        public stdClass $dependency,
        public string $label = 'x',
        public ?stdClass $nullable = null,
    ) {}
}

/**
 * NoConstructor.
 *
 * @internal fixture class for this test only
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class NoConstructor {}
