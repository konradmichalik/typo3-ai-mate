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

namespace KonradMichalik\Typo3AiMate\Mcp;

use KonradMichalik\Typo3AiMate\Mate\{ToolResult, Typo3CliRunner};
use Symfony\AI\Mate\Attribute\MateTool;

/**
 * ServiceDetailTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class ServiceDetailTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string $id class/interface name for an autowired service, or a string service id, e.g. Psr\Clock\ClockInterface
     */
    #[MateTool(
        name: 'typo3-service',
        title: 'TYPO3 DI Service Detail',
        description: 'Resolved class, constructor argument types, and public/shared flags for one DI service — why is this implementation injected, is it shared or a fresh instance per use. Public services only; TYPO3 makes most services private by default, and only public ones are reachable this way. Determining shared actually resolves the service, twice for a non-shared one; a well-behaved service\'s constructor does no I/O, but this tool cannot verify that in advance, unlike every other read-only tool here. Constructor arguments are the types PHP declares, not the values TYPO3 resolved (a service reference vs. a literal parameter look the same here). No tags, and no lazy/autowired/autoconfigured flags: both are compiler-only metadata gone once the container is running, and rebuilding the compiled container to get them back would mean replaying TYPO3\'s own bootstrap outside of it, which this tool does not do.',
    )]
    public function detail(string $id): string
    {
        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:service:detail', [$id], []));
    }
}
