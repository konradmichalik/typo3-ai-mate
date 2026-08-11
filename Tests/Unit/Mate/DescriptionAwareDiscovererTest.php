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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Mate;

use KonradMichalik\Typo3AiMate\Mate\{DescriptionAwareDiscoverer, ProfileProvider, ProfilerStateProvider, SiteHostsProvider, ToolDescriptionComputer, Typo3CliRunner};
use Mcp\Capability\Discovery\{DiscovererInterface, DiscoveryState};
use Mcp\Capability\Registry\{PromptReference, ResourceReference, ResourceTemplateReference, ToolReference};
use Mcp\Schema\{Prompt, ResourceDefinition, ResourceTemplate, Tool};
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DescriptionAwareDiscovererTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class DescriptionAwareDiscovererTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/ai-mate-discover-'.bin2hex(random_bytes(8));
        mkdir($this->rootDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    #[Test]
    public function delegatesDiscoveryArgumentsToTheInnerDiscoverer(): void
    {
        $inner = new class implements DiscovererInterface {
            /** @var array{string, array<string>, array<string>, array<string>}|null */
            public ?array $received = null;

            public function discover(string $basePath, array $directories, array $excludeDirs = [], array $namePatterns = self::DEFAULT_NAME_PATERNS): DiscoveryState
            {
                $this->received = [$basePath, $directories, $excludeDirs, $namePatterns];

                return new DiscoveryState();
            }
        };

        (new DescriptionAwareDiscoverer($inner, $this->computer()))->discover('/root', ['Classes/Mcp'], ['exclude'], ['*.php']);

        self::assertSame(['/root', ['Classes/Mcp'], ['exclude'], ['*.php']], $inner->received);
    }

    #[Test]
    public function leavesAToolWithAnUnchangedDescriptionAsTheSameReference(): void
    {
        $reference = $this->toolReference('typo3-tca', 'Resolved TCA.');
        $decorator = new DescriptionAwareDiscoverer($this->innerReturning(['typo3-tca' => $reference]), $this->computer());

        $result = $decorator->discover('/root', []);

        self::assertSame($reference, $result->getTools()['typo3-tca']);
    }

    #[Test]
    public function rebuildsAToolWhoseDescriptionChangedWhilePreservingItsOtherFields(): void
    {
        $reference = $this->toolReference('typo3-profiler-latest', 'Latest profile.');
        $decorator = new DescriptionAwareDiscoverer($this->innerReturning(['typo3-profiler-latest' => $reference]), $this->computer());

        $tool = $decorator->discover('/root', [])->getTools()['typo3-profiler-latest']->tool;

        self::assertStringContainsString('Latest profile.', (string) $tool->description);
        self::assertStringContainsString('typo3-profiler-start', (string) $tool->description);
        self::assertSame($reference->tool->name, $tool->name);
        self::assertSame($reference->tool->title, $tool->title);
        self::assertSame($reference->tool->inputSchema, $tool->inputSchema);
        self::assertSame($reference->tool->annotations, $tool->annotations);
    }

    #[Test]
    public function preservesTheToolsHandler(): void
    {
        $handler = static fn (): null => null;
        $reference = new ToolReference($this->tool('typo3-profiler-latest', 'Latest profile.'), $handler);
        $decorator = new DescriptionAwareDiscoverer($this->innerReturning(['typo3-profiler-latest' => $reference]), $this->computer());

        $result = $decorator->discover('/root', []);

        self::assertSame($handler, $result->getTools()['typo3-profiler-latest']->handler);
    }

    #[Test]
    public function passesResourcesPromptsAndResourceTemplatesThroughUnchanged(): void
    {
        $resources = ['sentinel' => new ResourceReference(new ResourceDefinition(uri: 'test://sentinel', name: 'sentinel_resource'), static fn (): null => null)];
        $prompts = ['sentinel' => new PromptReference(new Prompt(name: 'sentinel_prompt'), static fn (): null => null)];
        $resourceTemplates = ['sentinel' => new ResourceTemplateReference(new ResourceTemplate(uriTemplate: 'test://sentinel/{id}', name: 'sentinel_template'), static fn (): null => null)];
        $decorator = new DescriptionAwareDiscoverer($this->innerReturning([], $resources, $prompts, $resourceTemplates), $this->computer());

        $result = $decorator->discover('/root', []);

        self::assertSame($resources, $result->getResources());
        self::assertSame($prompts, $result->getPrompts());
        self::assertSame($resourceTemplates, $result->getResourceTemplates());
    }

    private function tool(string $name, string $description): Tool
    {
        return new Tool(
            name: $name,
            title: 'A title',
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => null],
            description: $description,
            annotations: null,
        );
    }

    private function toolReference(string $name, string $description): ToolReference
    {
        return new ToolReference($this->tool($name, $description), static fn (): null => null);
    }

    /**
     * @param array<string, ToolReference>             $tools
     * @param array<string, ResourceReference>         $resources
     * @param array<string, PromptReference>           $prompts
     * @param array<string, ResourceTemplateReference> $resourceTemplates
     */
    private function innerReturning(array $tools, array $resources = [], array $prompts = [], array $resourceTemplates = []): DiscovererInterface
    {
        return new class($tools, $resources, $prompts, $resourceTemplates) implements DiscovererInterface {
            /**
             * @param array<string, ToolReference>             $tools
             * @param array<string, ResourceReference>         $resources
             * @param array<string, PromptReference>           $prompts
             * @param array<string, ResourceTemplateReference> $resourceTemplates
             */
            public function __construct(
                private readonly array $tools,
                private readonly array $resources,
                private readonly array $prompts,
                private readonly array $resourceTemplates,
            ) {}

            public function discover(string $basePath, array $directories, array $excludeDirs = [], array $namePatterns = self::DEFAULT_NAME_PATERNS): DiscoveryState
            {
                return new DiscoveryState($this->tools, $this->resources, $this->prompts, $this->resourceTemplates);
            }
        };
    }

    private function computer(): ToolDescriptionComputer
    {
        return new ToolDescriptionComputer(
            new ProfileProvider($this->rootDir),
            new ProfilerStateProvider(new Typo3CliRunner($this->rootDir), $this->rootDir),
            new SiteHostsProvider($this->rootDir),
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $absolute = $path.'/'.$entry;
            is_dir($absolute) ? $this->removeDirectory($absolute) : unlink($absolute);
        }

        rmdir($path);
    }
}
