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

use KonradMichalik\Typo3AiMate\Mate\{ProfileProvider, ProfilerStateProvider, Typo3CliRunner};
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Symfony DI configuration for the symfony/ai-mate process (referenced via
 * composer.json extra.ai-mate.includes). This is NOT the TYPO3 DI container —
 * the #[MateTool] classes run in the Mate process and never boot TYPO3; they
 * reach TYPO3 by shelling out (Typo3CliRunner) or by reading profile artifacts.
 *
 * Only collaborators are declared here, never the tool and resource classes
 * themselves. ai-mate's ContainerFactory already registers every discovered
 * handler autowired and public, and it does so *before* this file is loaded —
 * so a `$services->set(SomeTool::class)` here replaces that public definition
 * with a private one, the compiler drops it, and every call to the tool fails
 * with "Handler ... is not registered as a service". A tool that ever needs an
 * explicit definition must therefore also carry ->public().
 *
 * %mate.root_dir% is the project root parameter provided by ai-mate v0.9.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Public so third-party Mate tools sharing this container can autowire it.
    $services->set(Typo3CliRunner::class)
        ->public()
        ->arg('$rootDir', '%mate.root_dir%');

    // Shared profile access needs the project root to locate var/log/profiles;
    // the profiler tools and the profile resource autowire it.
    $services->set(ProfileProvider::class)
        ->arg('$rootDir', '%mate.root_dir%');

    // Toggling profiling reads the state file below the project root and writes
    // through the profiler's console commands.
    $services->set(ProfilerStateProvider::class)
        ->arg('$rootDir', '%mate.root_dir%');
};
