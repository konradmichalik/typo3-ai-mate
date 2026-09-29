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

use KonradMichalik\Typo3AiMate\Command\Support\ConfigLayerResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ConfigLayerResolverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ConfigLayerResolverTest extends TestCase
{
    #[Test]
    public function aValueUntouchedAfterDefaultConfigurationIsSourcedThere(): void
    {
        $resolver = new ConfigLayerResolver(
            default: ['SYS' => ['sitename' => 'New TYPO3 site']],
            settings: ['SYS' => []],
        );

        $result = $resolver->resolve('SYS/sitename', live: ['SYS' => ['sitename' => 'New TYPO3 site']]);

        self::assertSame([
            'source' => 'default',
            'overrideChain' => ['default'],
        ], $result);
    }

    #[Test]
    public function aValueChangedInSettingsPhpIsSourcedThere(): void
    {
        $resolver = new ConfigLayerResolver(
            default: ['SYS' => ['sitename' => 'New TYPO3 site']],
            settings: ['SYS' => ['sitename' => 'My Project']],
        );

        $result = $resolver->resolve('SYS/sitename', live: ['SYS' => ['sitename' => 'My Project']]);

        self::assertSame([
            'source' => 'settings.php',
            'overrideChain' => ['default', 'settings.php'],
        ], $result);
    }

    #[Test]
    public function settingsPhpTouchingASiblingKeyDoesNotFalselyAttributeAnUntouchedOneThere(): void
    {
        // The project's settings.php sets DB/Connections/Default/password but never mentions
        // host: it inherits the core default. Layers are compared raw, never merged, so a
        // partial section in settings.php must not make an untouched sibling look changed.
        $resolver = new ConfigLayerResolver(
            default: ['DB' => ['Connections' => ['Default' => ['host' => 'localhost', 'password' => 'core-default']]]],
            settings: ['DB' => ['Connections' => ['Default' => ['password' => 'set-in-settings']]]],
        );

        $result = $resolver->resolve(
            'DB/Connections/Default/host',
            live: ['DB' => ['Connections' => ['Default' => ['host' => 'localhost', 'password' => 'set-in-settings']]]],
        );

        self::assertSame([
            'source' => 'default',
            'overrideChain' => ['default'],
        ], $result);
    }

    #[Test]
    public function aKeyThatOnlyExistsInSettingsPhpIsSourcedThereWithoutADefaultEntry(): void
    {
        $resolver = new ConfigLayerResolver(
            default: [],
            settings: ['EXTENSIONS' => ['my_ext' => ['apiUrl' => 'https://example.test']]],
        );

        $result = $resolver->resolve('EXTENSIONS/my_ext/apiUrl', live: ['EXTENSIONS' => ['my_ext' => ['apiUrl' => 'https://example.test']]]);

        self::assertSame([
            'source' => 'settings.php',
            'overrideChain' => ['settings.php'],
        ], $result);
    }

    #[Test]
    public function aLiveValueDifferingFromSettingsPhpIsAttributedBeyondSettingsPhp(): void
    {
        $resolver = new ConfigLayerResolver(
            default: ['DB' => ['Connections' => ['Default' => ['password' => 'default-placeholder']]]],
            settings: ['DB' => ['Connections' => ['Default' => ['password' => 'settings-placeholder']]]],
        );

        $result = $resolver->resolve(
            'DB/Connections/Default/password',
            live: ['DB' => ['Connections' => ['Default' => ['password' => 'env-resolved-secret']]]],
        );

        self::assertSame([
            'source' => 'beyond-settings-php',
            'overrideChain' => ['default', 'settings.php', 'beyond-settings-php'],
        ], $result);
    }

    #[Test]
    public function aKeyThatExistsOnlyLiveIsAttributedBeyondSettingsPhpWithoutEarlierLayers(): void
    {
        $resolver = new ConfigLayerResolver(default: [], settings: []);

        $result = $resolver->resolve('EXTENSIONS/my_ext/injectedAtRuntime', live: ['EXTENSIONS' => ['my_ext' => ['injectedAtRuntime' => true]]]);

        self::assertSame([
            'source' => 'beyond-settings-php',
            'overrideChain' => ['beyond-settings-php'],
        ], $result);
    }

    #[Test]
    public function aMissingComposerModeSettingsFileIsTreatedAsAnAbsentLayerNotAnError(): void
    {
        $resolver = new ConfigLayerResolver(
            default: ['SYS' => ['sitename' => 'New TYPO3 site']],
            settings: null,
        );

        $result = $resolver->resolve('SYS/sitename', live: ['SYS' => ['sitename' => 'New TYPO3 site']]);

        self::assertSame([
            'source' => 'default',
            'overrideChain' => ['default'],
        ], $result);
    }

    #[Test]
    public function aPathAbsentFromEveryLayerResolvesToNull(): void
    {
        $resolver = new ConfigLayerResolver(default: [], settings: []);

        self::assertNull($resolver->resolve('GHOST/does/not/exist', live: []));
    }

    #[Test]
    public function anUnchangedValuePresentInSettingsButNotFurtherOverriddenStopsAtSettingsPhp(): void
    {
        $resolver = new ConfigLayerResolver(
            default: ['MAIL' => ['transport' => 'sendmail']],
            settings: ['MAIL' => ['transport' => 'smtp']],
        );

        $result = $resolver->resolve('MAIL/transport', live: ['MAIL' => ['transport' => 'smtp']]);

        self::assertSame([
            'source' => 'settings.php',
            'overrideChain' => ['default', 'settings.php'],
        ], $result);
    }
}
