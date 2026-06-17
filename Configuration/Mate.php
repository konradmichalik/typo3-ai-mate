<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <km@move-elevator.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use KonradMichalik\Typo3AiMate\Mate\{ProfileProvider, Typo3CliRunner};
use KonradMichalik\Typo3AiMate\Mcp\{DeprecationsTool, EventsTool, ExtensionScannerTool, LogsTool, MiddlewaresTool, PageTool, PerformanceTool, ProfileResource, TcaTool, TypoScriptTool, UpgradeWizardsTool};
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Symfony DI configuration for the symfony/ai-mate process (referenced via
 * composer.json extra.ai-mate.includes). This is NOT the TYPO3 DI container —
 * the #[McpTool] classes run in the Mate process and never boot TYPO3; they
 * reach TYPO3 by shelling out (Typo3CliRunner) or by reading profile artifacts.
 *
 * %mate.root_dir% is the project root parameter provided by ai-mate v0.9.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Public so third-party MCP tools sharing this container can autowire it.
    $services->set(Typo3CliRunner::class)
        ->public()
        ->arg('$rootDir', '%mate.root_dir%');

    // CLI-wrapping tools (autowire Typo3CliRunner).
    $services->set(TcaTool::class);
    $services->set(PageTool::class);
    $services->set(TypoScriptTool::class);
    $services->set(MiddlewaresTool::class);
    $services->set(EventsTool::class);
    $services->set(LogsTool::class);
    $services->set(UpgradeWizardsTool::class);
    $services->set(ExtensionScannerTool::class);
    $services->set(DeprecationsTool::class);

    // Shared profile access needs the project root to locate var/log/profiles;
    // the profiler tools and the profile resource autowire it.
    $services->set(ProfileProvider::class)
        ->arg('$rootDir', '%mate.root_dir%');
    $services->set(PerformanceTool::class);
    $services->set(ProfileResource::class);
};
