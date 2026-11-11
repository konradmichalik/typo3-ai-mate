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

use KonradMichalik\Typo3AiMate\Command\UpgradeCompatibilityCommand;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * UpgradeCompatibilityCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class UpgradeCompatibilityCommandTest extends TestCase
{
    private UpgradeCompatibilityCommand $command;

    protected function setUp(): void
    {
        $this->command = new UpgradeCompatibilityCommand(self::createStub(PackageManager::class));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function targets(): iterable
    {
        yield 'major.minor covers every patch release' => ['14.3', '14.3.*'];
        yield 'full version is matched exactly' => ['14.3.7', '14.3.7'];
        yield 'surrounding whitespace is ignored' => [' 14.3 ', '14.3.*'];
        yield 'major only is rejected' => ['14', null];
        yield 'constraint syntax is rejected' => ['^14.3', null];
        yield 'empty target is rejected' => ['', null];
    }

    #[Test]
    #[DataProvider('targets')]
    public function targetConstraintAcceptsMajorMinorOrAFullVersion(string $target, ?string $expected): void
    {
        self::assertSame($expected, $this->command->targetConstraint($target));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function emConfRanges(): iterable
    {
        yield 'closed range' => ['13.4.0-14.4.99', '>=13.4.0 <=14.4.99'];
        yield 'spaces around the hyphen' => ['13.4.0 - 14.4.99', '>=13.4.0 <=14.4.99'];
        yield 'open upper bound' => ['13.4.0-', '>=13.4.0'];
        yield '0.0.0 upper bound means unbounded' => ['13.4.0-0.0.0', '>=13.4.0'];
        yield 'single version is a minimum' => ['13.4.0', '>=13.4.0'];
        yield 'open lower bound' => ['-14.4.99', '<=14.4.99'];
        yield 'fully unbounded says nothing' => ['0.0.0-0.0.0', null];
        yield 'empty says nothing' => ['', null];
        yield 'not a version range' => ['latest', null];
    }

    #[Test]
    #[DataProvider('emConfRanges')]
    public function emConfConstraintTranslatesTheMinMaxRange(string $range, ?string $expected): void
    {
        self::assertSame($expected, $this->command->emConfConstraint($range));
    }

    /**
     * @return iterable<string, array{string|null, string, string}>
     */
    public static function evaluations(): iterable
    {
        yield 'caret range allows the target' => ['^13.4 || ^14.3', '14.3.*', 'compatible'];
        yield 'caret range excludes the next major' => ['^13.4', '14.3.*', 'blocking'];
        yield 'a later patch of the target minor is enough' => ['^14.3.5', '14.3.*', 'compatible'];
        yield 'an exact target below the minimum blocks' => ['^14.3.5', '14.3.2', 'blocking'];
        yield 'emconf-style upper bound' => ['>=13.4.0 <=14.4.99', '14.3.*', 'compatible'];
        yield 'missing constraint is unknown' => [null, '14.3.*', 'unknown'];
        yield 'unparseable constraint is unknown' => ['not a constraint', '14.3.*', 'unknown'];
    }

    #[Test]
    #[DataProvider('evaluations')]
    public function evaluateComparesTheConstraintWithTheTarget(?string $constraint, string $target, string $expected): void
    {
        self::assertSame($expected, $this->command->evaluate($constraint, $target)['status']);
    }

    #[Test]
    public function evaluateExplainsWhyAConstraintIsUnknown(): void
    {
        self::assertStringContainsString('declares a typo3/cms-core', $this->command->evaluate(null, '14.3.*')['reason'] ?? '');
        self::assertStringContainsString('could not be parsed', $this->command->evaluate('not a constraint', '14.3.*')['reason'] ?? '');
    }

    #[Test]
    public function sortReportsBlockingFirstThenUnknownThenCompatibleOwnBeforeThirdParty(): void
    {
        $sorted = $this->command->sort([
            ['extension' => 'a_compatible', 'origin' => 'own', 'status' => 'compatible'],
            ['extension' => 'b_unknown', 'origin' => 'thirdParty', 'status' => 'unknown'],
            ['extension' => 'c_blocking', 'origin' => 'thirdParty', 'status' => 'blocking'],
            ['extension' => 'd_blocking', 'origin' => 'own', 'status' => 'blocking'],
        ]);

        self::assertSame(
            ['d_blocking', 'c_blocking', 'b_unknown', 'a_compatible'],
            array_column($sorted, 'extension'),
        );
    }
}
