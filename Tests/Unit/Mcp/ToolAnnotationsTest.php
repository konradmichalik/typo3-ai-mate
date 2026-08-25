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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function basename;
use function glob;
use function in_array;
use function sprintf;

/**
 * ToolAnnotationsTest.
 *
 * Every #[McpTool] must carry a {@see ToolAnnotations} hint so MCP clients can
 * tell read-only diagnostics apart from the handful of tools that write
 * something, without a client-side round trip per tool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ToolAnnotationsTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const MUTATING_TOOLS = ['typo3-profiler-start', 'typo3-profiler-stop', 'typo3-render-page'];

    #[Test]
    public function everyToolHasAnnotations(): void
    {
        $annotations = $this->collectAnnotations();

        self::assertCount(32, $annotations, 'Expected exactly 32 registered #[McpTool] methods.');

        foreach ($annotations as $name => $annotation) {
            self::assertNotNull($annotation, sprintf('Tool "%s" is missing #[McpTool] annotations.', $name));
        }
    }

    #[Test]
    public function readOnlyToolsAreMarkedReadOnly(): void
    {
        foreach ($this->collectAnnotations() as $name => $annotation) {
            if (in_array($name, self::MUTATING_TOOLS, true)) {
                continue;
            }

            self::assertNotNull($annotation, sprintf('Tool "%s" is missing #[McpTool] annotations.', $name));
            self::assertTrue($annotation->readOnlyHint, sprintf('Tool "%s" should be marked readOnlyHint: true.', $name));
        }
    }

    #[Test]
    public function profilerStartAndStopAreMarkedAsNonDestructiveWrites(): void
    {
        $annotations = $this->collectAnnotations();

        foreach (['typo3-profiler-start', 'typo3-profiler-stop'] as $name) {
            self::assertArrayHasKey($name, $annotations, sprintf('Tool "%s" was not found among the collected tools.', $name));
            $annotation = $annotations[$name];
            self::assertNotNull($annotation, sprintf('Tool "%s" is missing #[McpTool] annotations.', $name));
            self::assertFalse($annotation->readOnlyHint, sprintf('Tool "%s" should be marked readOnlyHint: false.', $name));
            self::assertFalse($annotation->destructiveHint, sprintf('Tool "%s" should be marked destructiveHint: false.', $name));
        }
    }

    #[Test]
    public function renderPageIsMarkedAsAWriteWithOpenWorldSideEffects(): void
    {
        $annotations = $this->collectAnnotations();
        self::assertArrayHasKey('typo3-render-page', $annotations);
        $annotation = $annotations['typo3-render-page'];

        self::assertNotNull($annotation);
        self::assertFalse($annotation->readOnlyHint);
        self::assertTrue($annotation->openWorldHint);
    }

    /**
     * @return array<string, ?ToolAnnotations>
     */
    private function collectAnnotations(): array
    {
        $annotations = [];

        foreach (self::toolClasses() as $class) {
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                foreach ($method->getAttributes(McpTool::class) as $attribute) {
                    $instance = $attribute->newInstance();
                    $name = $instance->name ?? $method->getName();
                    $annotations[$name] = $instance->annotations;
                }
            }
        }

        return $annotations;
    }

    /**
     * Every class in Classes/Mcp, read off the directory rather than listed:
     * a list is only as complete as the last person to remember it, and the six
     * tools that reached a release without annotations were exactly the ones
     * missing from it.
     *
     * @return list<class-string>
     */
    private static function toolClasses(): array
    {
        $classes = [];
        foreach (glob(__DIR__.'/../../../Classes/Mcp/*.php') ?: [] as $file) {
            /** @var class-string $class */
            $class = 'KonradMichalik\\Typo3AiMate\\Mcp\\'.basename($file, '.php');
            $classes[] = $class;
        }

        return $classes;
    }
}
