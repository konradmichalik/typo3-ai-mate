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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Container\ContainerFactory;
use Symfony\AI\Mate\Discovery\ReflectionDiscoverer;

use function dirname;
use function sprintf;

/**
 * MateContainerTest.
 *
 * Builds the real ai-mate container from `Configuration/Mate.php` and checks
 * that every discovered handler can actually be resolved out of it.
 *
 * This exists because the whole suite passed while no tool was callable at
 * all. Every other test constructs its tool directly (`new IconsTool($runner)`)
 * and therefore never touches the wiring. The defect: ai-mate's
 * ContainerFactory pre-registers each discovered handler autowired *and
 * public*, and it does that before loading this package's service file — so a
 * `$services->set(SomeTool::class)` there replaced the public definition with a
 * private one, the compiler dropped it, and every `tools:call` died with
 * "Handler ... is not registered as a service".
 *
 * A unit test that mocks the container cannot see that. This one must build
 * the real thing.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class MateContainerTest extends TestCase
{
    #[Test]
    public function everyDiscoveredHandlerIsResolvableFromTheContainer(): void
    {
        $projectRoot = dirname(__DIR__, 3);
        $capabilities = (new ReflectionDiscoverer())->discover($projectRoot, ['Classes/Mcp']);

        $handlers = [];
        foreach ($capabilities->getTools() as $tool) {
            $handlers[$tool->handlerClass] = true;
        }
        foreach ($capabilities->getResourceTemplates() as $template) {
            $handlers[$template->handlerClass] = true;
        }

        self::assertNotEmpty($handlers, 'No handler was discovered in Classes/Mcp, so this test would pass vacuously.');

        $container = (new ContainerFactory($projectRoot))->create();

        foreach (array_keys($handlers) as $handlerClass) {
            self::assertTrue(
                $container->has($handlerClass),
                sprintf('Handler "%s" is not a public service, so calling its tool fails at runtime.', $handlerClass),
            );
            self::assertInstanceOf($handlerClass, $container->get($handlerClass));
        }
    }
}
