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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command\Support;

use KonradMichalik\Typo3AiMate\Command\Support\ClassOwnerResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ClassOwnerResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ClassOwnerResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/typo3-ai-mate-owner-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/vendor/acme/base/Classes', 0o777, true);
        mkdir($this->root.'/vendor/acme/base/nested_ext/Classes', 0o777, true);
        mkdir($this->root.'/packages/site/Classes', 0o777, true);
        touch($this->root.'/vendor/acme/base/nested_ext/Classes/Thing.php');
        symlink($this->root.'/packages/site', $this->root.'/vendor/acme/site');
        touch($this->root.'/packages/site/Classes/Thing.php');
    }

    protected function tearDown(): void
    {
        unlink($this->root.'/vendor/acme/site');
        unlink($this->root.'/packages/site/Classes/Thing.php');
        unlink($this->root.'/vendor/acme/base/nested_ext/Classes/Thing.php');
        foreach (['/packages/site/Classes', '/packages/site', '/packages', '/vendor/acme/base/nested_ext/Classes', '/vendor/acme/base/nested_ext', '/vendor/acme/base/Classes', '/vendor/acme/base', '/vendor/acme', '/vendor', ''] as $dir) {
            rmdir($this->root.$dir);
        }
    }

    #[Test]
    public function theReplacementClassFileDecidesTheOwnerAndTheMostSpecificPackageWins(): void
    {
        $resolver = $this->resolver(['Nested\\Thing' => $this->root.'/vendor/acme/base/nested_ext/Classes/Thing.php']);

        self::assertSame(
            ['extension' => 'nested_ext', 'package' => 'acme/nested-ext', 'determinedBy' => 'classFile'],
            $resolver->resolve('Nested\\Thing'),
        );
    }

    #[Test]
    public function aPackageReportedThroughASymlinkStillOwnsTheResolvedClassFile(): void
    {
        $resolver = $this->resolver(['Site\\Thing' => $this->root.'/packages/site/Classes/Thing.php']);

        self::assertSame(
            ['extension' => 'site', 'package' => 'acme/site', 'determinedBy' => 'classFile'],
            $resolver->resolve('Site\\Thing'),
        );
    }

    #[Test]
    public function aClassTheAutoloaderCannotFindFallsBackToTheLongestMatchingPsr4Namespace(): void
    {
        $resolver = $this->resolver([]);

        self::assertSame(
            ['extension' => 'nested_ext', 'package' => 'acme/nested-ext', 'determinedBy' => 'namespace'],
            $resolver->resolve('\\Acme\\Base\\Nested\\Missing'),
        );
    }

    #[Test]
    public function aClassOutsideEveryActivePackageHasNoOwner(): void
    {
        $resolver = $this->resolver(['Other\\Thing' => '/somewhere/else/Thing.php']);

        self::assertSame(
            ['extension' => null, 'package' => null, 'determinedBy' => null],
            $resolver->resolve('Other\\Thing'),
        );
    }

    /**
     * @param array<string, string> $files
     */
    private function resolver(array $files): ClassOwnerResolver
    {
        return new ClassOwnerResolver(
            [
                ['extension' => 'base', 'package' => 'acme/base', 'path' => $this->root.'/vendor/acme/base/', 'namespaces' => ['Acme\\Base\\']],
                ['extension' => 'nested_ext', 'package' => 'acme/nested-ext', 'path' => $this->root.'/vendor/acme/base/nested_ext/', 'namespaces' => ['Acme\\Base\\Nested\\']],
                ['extension' => 'site', 'package' => 'acme/site', 'path' => $this->root.'/vendor/acme/site/', 'namespaces' => ['Site\\']],
            ],
            static fn (string $class): ?string => $files[$class] ?? null,
        );
    }
}
