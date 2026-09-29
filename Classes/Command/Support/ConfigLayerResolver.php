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

namespace KonradMichalik\Typo3AiMate\Command\Support;

use function array_key_exists;
use function end;
use function explode;
use function is_array;
use function trim;

/**
 * ConfigLayerResolver.
 *
 * Attributes a TYPO3_CONF_VARS path to the configuration layer that last set
 * it, by comparing three states for the requested path: the TYPO3 core's
 * DefaultConfiguration.php, that merged with the installation's composer-mode
 * config/system/settings.php, and the live $GLOBALS['TYPO3_CONF_VARS'] the
 * caller already has.
 *
 * Deliberately does not go further than settings.php: config/system/additional.php
 * is arbitrary PHP, not data, and TYPO3 executes it once during bootstrap as a
 * side effect (env var reads, conditionals, …). Re-running it a second time to
 * observe its effect would execute the installation's own code a second time
 * outside of TYPO3's normal boot, which is a correctness and safety risk this
 * read-only tool does not take. Anything that changed a value after
 * settings.php, whether additional.php, an extension's ext_localconf.php, or
 * other runtime code, is reported as one bucket: "beyond-settings-php".
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ConfigLayerResolver
{
    private const LAYER_DEFAULT = 'default';
    private const LAYER_SETTINGS = 'settings.php';
    private const LAYER_BEYOND_SETTINGS = 'beyond-settings-php';

    /**
     * @param array<string, mixed>      $default  TYPO3 core's DefaultConfiguration.php
     * @param array<string, mixed>|null $settings config/system/settings.php merged over $default; null when the
     *                                            installation has no composer-mode settings file
     */
    public function __construct(
        private readonly array $default,
        private readonly ?array $settings,
    ) {}

    /**
     * @param array<string, mixed> $live the resolved $GLOBALS['TYPO3_CONF_VARS'] (or the equivalent subtree the
     *                                   requested path is rooted in)
     *
     * @return array{source: string, overrideChain: list<string>}|null null when the path exists in none of the
     *                                                                 three states
     */
    public function resolve(string $path, array $live): ?array
    {
        $overrideChain = [];
        $lastFound = false;
        $lastValue = null;

        foreach ($this->layers() as $layer => $state) {
            [$found, $value] = self::traverse($state, $path);
            if ($found && (!$lastFound || $value !== $lastValue)) {
                $overrideChain[] = $layer;
            }
            if ($found) {
                $lastFound = true;
                $lastValue = $value;
            }
        }

        [$liveFound, $liveValue] = self::traverse($live, $path);
        if (!$liveFound && !$lastFound) {
            return null;
        }
        if ($liveFound && (!$lastFound || $liveValue !== $lastValue)) {
            $overrideChain[] = self::LAYER_BEYOND_SETTINGS;
        }

        return [
            'source' => end($overrideChain) ?: self::LAYER_DEFAULT,
            'overrideChain' => $overrideChain,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function layers(): array
    {
        $layers = [self::LAYER_DEFAULT => $this->default];
        if (null !== $this->settings) {
            $layers[self::LAYER_SETTINGS] = $this->settings;
        }

        return $layers;
    }

    /**
     * Duplicated from {@see \KonradMichalik\Typo3AiMate\Command\ConfigCommand::traverse()} on purpose: that
     * traverse() is public API of a Command class, and this Support class must not depend on one (wrong
     * dependency direction). Extract to a shared utility if a third caller ever needs the same walk.
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: bool, 1: mixed}
     */
    private static function traverse(array $data, string $path): array
    {
        $current = $data;
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ('' === $segment || !is_array($current) || !array_key_exists($segment, $current)) {
                return [false, null];
            }
            $current = $current[$segment];
        }

        return [true, $current];
    }
}
