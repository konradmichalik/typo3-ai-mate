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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function basename;
use function class_exists;
use function glob;
use function sprintf;

/**
 * ToolOutputSchemaTest.
 *
 * Every tool must declare an outputSchema (#94): the MCP SDK only populates
 * structuredContent for a tool whose declared schema exists, and every schema's
 * field descriptions must state what a negative result means, the way
 * IconLookup's registered=false does.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ToolOutputSchemaTest extends TestCase
{
    #[Test]
    public function everyToolDeclaresAnObjectOutputSchema(): void
    {
        $checked = 0;

        foreach (self::toolClasses() as $class) {
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                foreach ($method->getAttributes(McpTool::class) as $attribute) {
                    $instance = $attribute->newInstance();
                    $name = $instance->name ?? $method->getName();
                    ++$checked;

                    self::assertIsArray($instance->outputSchema, sprintf('Tool "%s" (%s) is missing an outputSchema.', $name, $class));
                    self::assertSame('object', $instance->outputSchema['type'] ?? null, sprintf('Tool "%s" (%s) outputSchema must be type object per the MCP spec.', $name, $class));
                    self::assertIsArray($instance->outputSchema['properties'] ?? null, sprintf('Tool "%s" (%s) outputSchema must declare properties.', $name, $class));
                }
            }
        }

        self::assertSame(32, $checked, 'Expected exactly 32 registered #[McpTool] methods to be checked.');
    }

    /**
     * Every class in Classes/Mcp, read off the directory rather than listed:
     * a list is only as complete as the last person to remember it. Excludes
     * ProfileResource, an MCP resource rather than a tool (no #[McpTool] method).
     *
     * @return list<class-string>
     */
    private static function toolClasses(): array
    {
        $classes = [];
        foreach (glob(__DIR__.'/../../../Classes/Mcp/*.php') ?: [] as $file) {
            $class = 'KonradMichalik\\Typo3AiMate\\Mcp\\'.basename($file, '.php');
            self::assertTrue(
                class_exists($class),
                sprintf('%s does not declare the class its filename promises, so the surface cannot be read off the directory.', $file),
            );
            if (\KonradMichalik\Typo3AiMate\Mcp\ProfileResource::class === $class) {
                continue;
            }
            /* @var class-string $class */
            $classes[] = $class;
        }

        return $classes;
    }
}
