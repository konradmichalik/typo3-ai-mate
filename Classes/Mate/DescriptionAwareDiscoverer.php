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

namespace KonradMichalik\Typo3AiMate\Mate;

use Mcp\Capability\Discovery\{DiscovererInterface, DiscoveryState};
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Tool;

/**
 * DescriptionAwareDiscoverer.
 *
 * Decorates ai-mate's #[McpTool] attribute discovery (`Configuration/Mate.php`
 * registers this as a decorator of {@see DiscovererInterface}) to rewrite a
 * handful of tool descriptions with runtime state computed by
 * {@see ToolDescriptionComputer}, since a PHP attribute argument must be a
 * compile-time constant and cannot call out to the filesystem itself.
 *
 * `DiscovererInterface`/`DiscoveryState`/`ToolReference` are marked
 * `@internal` by mcp/sdk; composer.json pins `mcp/sdk: ^0.7`, so re-verify
 * this against the SDK's discovery internals on every minor bump.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class DescriptionAwareDiscoverer implements DiscovererInterface
{
    public function __construct(
        private DiscovererInterface $inner,
        private ToolDescriptionComputer $descriptions,
    ) {}

    public function discover(string $basePath, array $directories, array $excludeDirs = [], array $namePatterns = self::DEFAULT_NAME_PATERNS): DiscoveryState
    {
        $state = $this->inner->discover($basePath, $directories, $excludeDirs, $namePatterns);

        $tools = [];
        foreach ($state->getTools() as $name => $reference) {
            $tools[$name] = $this->withComputedDescription($name, $reference);
        }

        return new DiscoveryState($tools, $state->getResources(), $state->getPrompts(), $state->getResourceTemplates());
    }

    private function withComputedDescription(string $name, ToolReference $reference): ToolReference
    {
        $tool = $reference->tool;
        $description = $this->descriptions->compute($name, (string) $tool->description);

        if ($description === $tool->description) {
            return $reference;
        }

        return new ToolReference(
            new Tool(
                name: $tool->name,
                title: $tool->title,
                inputSchema: $tool->inputSchema,
                description: $description,
                annotations: $tool->annotations,
                icons: $tool->icons,
                meta: $tool->meta,
                outputSchema: $tool->outputSchema,
            ),
            $reference->handler,
        );
    }
}
