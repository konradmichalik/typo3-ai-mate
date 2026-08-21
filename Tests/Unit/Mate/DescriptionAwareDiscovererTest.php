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
use KonradMichalik\Typo3AiMate\Support\RuntimeArtifacts;
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
        $reference = $this->toolReference('typo3-render-page', 'Render a page.');
        $decorator = new DescriptionAwareDiscoverer($this->innerReturning(['typo3-render-page' => $reference]), $this->computer());

        $tool = $decorator->discover('/root', [])->getTools()['typo3-render-page']->tool;

        self::assertStringContainsString('Render a page.', (string) $tool->description);
        self::assertStringContainsString('no host is currently allowed', (string) $tool->description);
        self::assertSame($reference->tool->name, $tool->name);
        self::assertSame($reference->tool->title, $tool->title);
        self::assertSame($reference->tool->inputSchema, $tool->inputSchema);
        self::assertSame($reference->tool->annotations, $tool->annotations);
    }

    #[Test]
    public function preservesTheToolsHandler(): void
    {
        $handler = static fn (): null => null;
        $reference = new ToolReference($this->tool('typo3-render-page', 'Render a page.'), $handler);
        $decorator = new DescriptionAwareDiscoverer($this->innerReturning(['typo3-render-page' => $reference]), $this->computer());

        $result = $decorator->discover('/root', []);

        self::assertSame($handler, $result->getTools()['typo3-render-page']->handler);
    }

    #[Test]
    public function leavesTheProfilerClusterUnregisteredWhileThereIsNothingToRead(): void
    {
        $tools = [
            'typo3-profiler-start' => $this->toolReference('typo3-profiler-start', 'Start profiling.'),
            'typo3-profiler-latest' => $this->toolReference('typo3-profiler-latest', 'Latest profile.'),
            'typo3-profiler-get' => $this->toolReference('typo3-profiler-get', 'Get a profile.'),
            'typo3-tca' => $this->toolReference('typo3-tca', 'Resolved TCA.'),
        ];

        $registered = (new DescriptionAwareDiscoverer($this->innerReturning($tools), $this->computer()))
            ->discover('/root', [])
            ->getTools();

        // Only the tool that brings profiles into existence survives.
        self::assertArrayHasKey('typo3-profiler-start', $registered);
        self::assertArrayNotHasKey('typo3-profiler-latest', $registered);
        self::assertArrayNotHasKey('typo3-profiler-get', $registered);
        // An unrelated tool is untouched.
        self::assertArrayHasKey('typo3-tca', $registered);

        self::assertStringContainsString(
            'not registered in this session',
            (string) $registered['typo3-profiler-start']->tool->description,
        );
    }

    #[Test]
    public function leavesTheLogsClusterUnregisteredWhileTheLogIsEmpty(): void
    {
        $tools = [
            'typo3-logs-tail' => $this->toolReference('typo3-logs-tail', 'Tail the log.'),
            'typo3-logs-search' => $this->toolReference('typo3-logs-search', 'Search the log.'),
            'typo3-logs-by-level' => $this->toolReference('typo3-logs-by-level', 'Filter by level.'),
        ];

        $registered = (new DescriptionAwareDiscoverer($this->innerReturning($tools), $this->computer()))
            ->discover('/root', [])
            ->getTools();

        self::assertSame(['typo3-logs-tail'], array_keys($registered));
        self::assertStringContainsString('The log is empty', (string) $registered['typo3-logs-tail']->tool->description);
    }

    #[Test]
    public function registersTheWholeClusterOnceThereIsSomethingToRead(): void
    {
        mkdir($this->rootDir.'/var/log/profiles', 0o700, true);
        file_put_contents(
            $this->rootDir.'/var/log/profiles/abc.json',
            (string) json_encode(['token' => 'abc', 'time' => '2026-06-15T10:00:00+00:00', 'schemaVersion' => 1]),
        );
        file_put_contents($this->rootDir.'/var/log/typo3_example.log', "something happened\n");

        $tools = [
            'typo3-profiler-latest' => $this->toolReference('typo3-profiler-latest', 'Latest profile.'),
            'typo3-logs-search' => $this->toolReference('typo3-logs-search', 'Search the log.'),
        ];

        $registered = (new DescriptionAwareDiscoverer($this->innerReturning($tools), $this->computer()))
            ->discover('/root', [])
            ->getTools();

        self::assertArrayHasKey('typo3-profiler-latest', $registered);
        self::assertArrayHasKey('typo3-logs-search', $registered);
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
            new RuntimeArtifacts($this->rootDir),
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
