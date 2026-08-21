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

use function in_array;

/**
 * DescriptionAwareDiscoverer.
 *
 * Decorates ai-mate's #[McpTool] attribute discovery (`Configuration/Mate.php`
 * registers this as a decorator of {@see DiscovererInterface}) to apply runtime
 * state to the discovered tool set, which a PHP attribute cannot do itself
 * because its arguments must be compile-time constants:
 *
 * - descriptions get a state sentence spliced in by {@see ToolDescriptionComputer},
 * - a cluster whose subject does not exist yet is not registered at all
 *   ({@see ToolClusterGate}), leaving only its entry-point tool.
 *
 * `DiscovererInterface`/`DiscoveryState`/`ToolReference` are marked
 * `@internal` by mcp/sdk; composer.json pins `mcp/sdk: ^0.7`, so re-verify
 * this against the SDK's discovery internals on every minor bump.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
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
        // The same runtime state that produces the description suffixes decides
        // this, so it is read once, there.
        $suppressed = $this->descriptions->suppressedTools();

        $tools = [];
        foreach ($state->getTools() as $name => $reference) {
            if (in_array($name, $suppressed, true)) {
                continue;
            }
            $tools[$name] = $this->withComputedDescription($name, $reference);
        }

        return new DiscoveryState($tools, $state->getResources(), $state->getPrompts(), $state->getResourceTemplates());
    }

    private function withComputedDescription(string $name, ToolReference $reference): ToolReference
    {
        $tool = $reference->tool;
        if (null === $tool->description) {
            return $reference;
        }

        $description = $this->descriptions->compute($name, $tool->description);
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
