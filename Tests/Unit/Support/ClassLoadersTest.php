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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Support;

use Composer\Autoload\ClassLoader;
use KonradMichalik\Typo3AiMate\Support\ClassLoaders;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * ClassLoadersTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ClassLoadersTest extends TestCase
{
    #[Test]
    public function addsTheBootedLoaderWhenItIsNotRegistered(): void
    {
        $booted = new ClassLoader();

        $loaders = ClassLoaders::with($booted);

        self::assertSame($booted, $loaders[count($loaders) - 1]);
    }

    #[Test]
    public function doesNotRepeatABootedLoaderThatIsAlreadyRegistered(): void
    {
        $registered = array_values(ClassLoader::getRegisteredLoaders());
        if ([] === $registered) {
            self::markTestSkipped('No Composer loader is registered in this autoload stack.');
        }

        self::assertSame($registered, ClassLoaders::with($registered[0]));
    }
}
