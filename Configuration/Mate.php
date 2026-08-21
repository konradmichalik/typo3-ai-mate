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

use KonradMichalik\Typo3AiMate\Mate\{DescriptionAwareDiscoverer, ProfileProvider, ProfilerStateProvider, SiteHostsProvider, ToolDescriptionComputer, Typo3CliRunner};
use KonradMichalik\Typo3AiMate\Mcp\{ChangelogSearchTool, CommandsTool, ConfigTool, DbSchemaTool, DeprecationsTool, EventsTool, ExtensionScannerTool, FluidResolveTool, InfoTool, LogsTool, MiddlewaresTool, PageTool, PerformanceTool, ProfileResource, ProfilerControlTool, RenderPageTool, SiteTool, TcaTool, TsConfigTool, TypoScriptTool, UpgradeWizardsTool};
use KonradMichalik\Typo3AiMate\Support\RuntimeArtifacts;
use Mcp\Capability\Discovery\DiscovererInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

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
    $services->set(TsConfigTool::class);
    $services->set(FluidResolveTool::class);
    $services->set(MiddlewaresTool::class);
    $services->set(EventsTool::class);
    $services->set(LogsTool::class);
    $services->set(UpgradeWizardsTool::class);
    $services->set(ExtensionScannerTool::class);
    $services->set(DeprecationsTool::class);
    $services->set(RenderPageTool::class);
    $services->set(CommandsTool::class);
    $services->set(DbSchemaTool::class);
    $services->set(ConfigTool::class);
    $services->set(SiteTool::class);
    $services->set(InfoTool::class);
    $services->set(ChangelogSearchTool::class);

    // Shared profile access needs the project root to locate var/log/profiles;
    // the profiler tools and the profile resource autowire it.
    $services->set(ProfileProvider::class)
        ->arg('$rootDir', '%mate.root_dir%');
    $services->set(PerformanceTool::class);
    $services->set(ProfileResource::class);

    // Toggling profiling reads the state file below the project root and writes
    // through the profiler's console commands, so it needs both collaborators.
    $services->set(ProfilerStateProvider::class)
        ->arg('$rootDir', '%mate.root_dir%');
    $services->set(ProfilerControlTool::class);

    // Advisory-only reader of config/sites/*/config.yaml for the render-page
    // tool's description; the actual SSRF guard stays in
    // RenderPageCommand::isAllowedHost(), sourced from the booted SiteFinder.
    $services->set(SiteHostsProvider::class)
        ->arg('$rootDir', '%mate.root_dir%');

    // Whether anything has been logged decides, together with the profiler
    // state, which tool clusters are registered at all.
    $services->set(RuntimeArtifacts::class)
        ->arg('$rootDir', '%mate.root_dir%');
    $services->set(ToolDescriptionComputer::class);

    // #[McpTool] descriptions are static strings (a PHP attribute argument must
    // be a compile-time constant), so runtime state is spliced in here, once
    // per server start, by decorating the SDK's discovery step.
    $services->set(DescriptionAwareDiscoverer::class)
        ->decorate(DiscovererInterface::class)
        ->arg('$inner', service('.inner'));
};
