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

use Composer\Autoload\ClassLoader;
use KonradMichalik\Ttt\Attribute\WithEnvironment;
use KonradMichalik\Typo3AiMate\Command\OverridesCommand;
use KonradMichalik\Typo3AiMate\Support\{Cast, Redactor};
use KonradMichalik\Typo3AiMate\Tests\Unit\Command\Fixtures\UnloadedXclass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Package\{PackageInterface, PackageManager};

use function dirname;

/**
 * OverridesCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[WithEnvironment]
final class OverridesCommandTest extends TestCase
{
    private mixed $originalConfVars = null;

    protected function setUp(): void
    {
        $this->originalConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
    }

    protected function tearDown(): void
    {
        if (null === $this->originalConfVars) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->originalConfVars;
        }
    }

    #[Test]
    public function listsEveryXclassWithTheExtensionThatOwnsTheReplacementClass(): void
    {
        $this->registerXclasses(['Vendor\\Original' => ['className' => Cast::class]]);

        [$exitCode, $result] = $this->runCommand();

        self::assertSame(0, $exitCode);
        self::assertSame(1, $result['xclassCount']);
        self::assertSame([[
            'original' => 'Vendor\\Original',
            'replacement' => Cast::class,
            'effectiveClass' => Cast::class,
            'extension' => 'typo3_ai_mate',
            'package' => 'konradmichalik/typo3-ai-mate',
            'ownerDeterminedBy' => 'classFile',
        ]], $result['xclasses']);
    }

    #[Test]
    public function resolvingTheOwnerFindsTheClassFileWithoutLoadingTheReplacementClass(): void
    {
        $this->registerXclasses(['Vendor\\Original' => ['className' => UnloadedXclass::class]]);

        [, $result] = $this->runCommand();

        self::assertSame('classFile', $this->xclasses($result)[0]['ownerDeterminedBy']);
        self::assertFalse(class_exists(UnloadedXclass::class, false));
    }

    #[Test]
    public function aChainedXclassReportsTheClassThatIsEffectivelyInstantiated(): void
    {
        $this->registerXclasses([
            'Vendor\\Original' => ['className' => Cast::class],
            Cast::class => ['className' => Redactor::class],
        ]);

        [, $result] = $this->runCommand();

        self::assertSame(Redactor::class, $this->xclasses($result)[0]['effectiveClass']);
        self::assertSame(Redactor::class, $this->xclasses($result)[1]['effectiveClass']);
    }

    #[Test]
    public function registrationsWithoutAReplacementClassAreIgnoredLikeTypo3IgnoresThem(): void
    {
        $this->registerXclasses([
            'Vendor\\Empty' => ['className' => ''],
            'Vendor\\Missing' => [],
            'Vendor\\Broken' => 'not-an-array',
        ]);

        [, $result] = $this->runCommand();

        self::assertSame(0, $result['xclassCount']);
        self::assertSame([], $result['xclasses']);
    }

    #[Test]
    public function aClassFilterMatchesTheOriginalCaseInsensitivelyAndWithALeadingBackslash(): void
    {
        $this->registerXclasses([
            'Vendor\\Original' => ['className' => Cast::class],
            'Vendor\\Unrelated' => ['className' => Redactor::class],
        ]);

        [$exitCode, $result] = $this->runCommand(['--class' => '\\vendor\\original']);

        self::assertSame(0, $exitCode);
        self::assertSame('vendor\\original', $result['class']);
        self::assertTrue($result['found']);
        self::assertSame(['Vendor\\Original'], array_column($this->xclasses($result), 'original'));
    }

    #[Test]
    public function aClassFilterAlsoMatchesTheReplacementClass(): void
    {
        $this->registerXclasses([
            'Vendor\\Original' => ['className' => Cast::class],
            'Vendor\\Unrelated' => ['className' => Redactor::class],
        ]);

        [, $result] = $this->runCommand(['--class' => Cast::class]);

        self::assertTrue($result['found']);
        self::assertSame(['Vendor\\Original'], array_column($this->xclasses($result), 'original'));
    }

    #[Test]
    public function aClassWithoutAnyXclassIsAnsweredAsNotFound(): void
    {
        $this->registerXclasses(['Vendor\\Original' => ['className' => Cast::class]]);

        [$exitCode, $result] = $this->runCommand(['--class' => 'Vendor\\Elsewhere']);

        self::assertSame(0, $exitCode);
        self::assertFalse($result['found']);
        self::assertSame([], $result['xclasses']);
    }

    /**
     * @param array<string, mixed> $objects
     */
    private function registerXclasses(array $objects): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['SYS' => ['Objects' => $objects]];
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<mixed>}
     */
    private function runCommand(array $input = []): array
    {
        $tester = new CommandTester(new OverridesCommand($this->packageManager(), $this->classLoader()));
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }

    /**
     * @param array<mixed> $result
     *
     * @return list<array<mixed>>
     */
    private function xclasses(array $result): array
    {
        $xclasses = $result['xclasses'] ?? null;
        self::assertIsArray($xclasses);

        return array_values(array_map(static function (mixed $xclass): array {
            self::assertIsArray($xclass);

            return $xclass;
        }, $xclasses));
    }

    private function classLoader(): ClassLoader
    {
        $loader = require dirname(__DIR__, 3).'/vendor/autoload.php';
        self::assertInstanceOf(ClassLoader::class, $loader);

        return $loader;
    }

    private function packageManager(): PackageManager
    {
        $package = self::createStub(PackageInterface::class);
        $package->method('getPackageKey')->willReturn('typo3_ai_mate');
        $package->method('getPackagePath')->willReturn(dirname(__DIR__, 3).'/');
        $package->method('getValueFromComposerManifest')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'name' => 'konradmichalik/typo3-ai-mate',
            'autoload' => json_decode('{"psr-4": {"KonradMichalik\\\\Typo3AiMate\\\\": "Classes/"}}'),
            default => null,
        });

        $packageManager = self::createStub(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([$package]);

        return $packageManager;
    }
}
