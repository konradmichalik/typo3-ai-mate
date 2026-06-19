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

use ArrayObject;
use KonradMichalik\Typo3AiMate\Command\MiddlewaresCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Http\MiddlewareStackResolver;

/**
 * MiddlewaresCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MiddlewaresCommandTest extends TestCase
{
    #[Test]
    public function mapMiddlewaresUnwrapsTheTargetFromAnArrayDefinition(): void
    {
        $mapped = MiddlewaresCommand::mapMiddlewares([
            'typo3/cms-frontend/timetracker' => ['target' => 'TYPO3\\CMS\\Frontend\\Middleware\\TimeTrackerInitialization'],
        ]);

        self::assertSame(
            [['identifier' => 'typo3/cms-frontend/timetracker', 'target' => 'TYPO3\\CMS\\Frontend\\Middleware\\TimeTrackerInitialization']],
            $mapped,
        );
    }

    #[Test]
    public function mapMiddlewaresKeepsScalarValuesAsTheTarget(): void
    {
        $mapped = MiddlewaresCommand::mapMiddlewares([
            'some/identifier' => 'Some\\Middleware\\ClassName',
        ]);

        self::assertSame(
            [['identifier' => 'some/identifier', 'target' => 'Some\\Middleware\\ClassName']],
            $mapped,
        );
    }

    #[Test]
    public function mapMiddlewaresNullsAnArrayDefinitionWithoutTarget(): void
    {
        $mapped = MiddlewaresCommand::mapMiddlewares([
            'broken/identifier' => ['before' => ['x']],
        ]);

        self::assertNull($mapped[0]['target']);
        self::assertSame('broken/identifier', $mapped[0]['identifier']);
    }

    #[Test]
    public function mapMiddlewaresNullsNonStringIdentifiers(): void
    {
        $mapped = MiddlewaresCommand::mapMiddlewares([
            0 => 'Some\\Middleware',
        ]);

        self::assertNull($mapped[0]['identifier']);
    }

    #[Test]
    public function executeEmitsTheResolvedFrontendStackAsJson(): void
    {
        $resolver = $this->createMock(MiddlewareStackResolver::class);
        $resolver->method('resolve')->with('frontend')->willReturn($this->resolvedStack([
            'typo3/cms-frontend/timetracker' => ['target' => 'Some\\Middleware'],
        ]));

        $tester = new CommandTester(new MiddlewaresCommand($resolver));
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('frontend', $result['stack']);
        $middlewares = $result['middlewares'];
        self::assertIsArray($middlewares);
        $first = $middlewares[0];
        self::assertIsArray($first);
        self::assertSame('typo3/cms-frontend/timetracker', $first['identifier']);
    }

    #[Test]
    public function executeSelectsTheBackendStackWhenRequested(): void
    {
        $resolver = $this->createMock(MiddlewareStackResolver::class);
        $resolver->expects(self::once())->method('resolve')->with('backend')->willReturn($this->resolvedStack([]));

        $tester = new CommandTester(new MiddlewaresCommand($resolver));
        $tester->execute(['--stack' => 'backend']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('backend', $result['stack']);
    }

    #[Test]
    public function executeReportsResolverFailuresAsAnError(): void
    {
        $resolver = $this->createMock(MiddlewareStackResolver::class);
        $resolver->method('resolve')->willThrowException(new RuntimeException('boom'));

        $tester = new CommandTester(new MiddlewaresCommand($resolver));
        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('boom', $result['error']);
    }

    /**
     * MiddlewareStackResolver::resolve() returns an array on TYPO3 v13 but an
     * ArrayObject on newer cores. Return whatever the installed version declares
     * so the mock's value satisfies the method's return type.
     *
     * @param array<string, mixed> $middlewares
     *
     * @return iterable<string, mixed>
     */
    private function resolvedStack(array $middlewares): iterable
    {
        $returnType = (new ReflectionMethod(MiddlewareStackResolver::class, 'resolve'))->getReturnType();

        return $returnType instanceof ReflectionNamedType && 'ArrayObject' === $returnType->getName()
            ? new ArrayObject($middlewares)
            : $middlewares;
    }
}
