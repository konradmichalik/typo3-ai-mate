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

use KonradMichalik\Typo3AiMate\Support\Cast;

use function in_array;
use function sprintf;

/**
 * ToolDescriptionComputer.
 *
 * Appends a runtime-state sentence to a handful of tool descriptions, computed
 * once from cheap filesystem reads when the Mate server starts (never a TYPO3
 * boot — see {@see ProfileProvider}, {@see ProfilerStateProvider},
 * {@see SiteHostsProvider}). This is the field an assistant reads *before*
 * deciding to call a tool, so a precondition stated here (e.g. "no profiles
 * exist yet, run typo3-profiler-start first") prevents a wasted call instead
 * of only explaining it in the result.
 *
 * Descriptions are captured by the MCP client at connection time, so a state
 * change mid-session is not reflected until it reconnects — the same caveat
 * the README already documents for schema changes after `composer update`.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ToolDescriptionComputer
{
    /**
     * @var list<string>
     */
    private const PROFILE_READ_TOOLS = ['typo3-profiler-latest', 'typo3-profiler-list', 'typo3-profiler-search', 'typo3-profiler-get'];

    /**
     * @var list<string>
     */
    private const PROFILER_CONTROL_TOOLS = ['typo3-profiler-start', 'typo3-profiler-stop', 'typo3-profiler-status'];

    private const RENDER_PAGE_TOOL = 'typo3-render-page';

    public function __construct(
        private ProfileProvider $profiles,
        private ProfilerStateProvider $profilerState,
        private SiteHostsProvider $siteHosts,
    ) {}

    public function compute(string $toolName, string $staticDescription): string
    {
        $suffix = match (true) {
            in_array($toolName, self::PROFILE_READ_TOOLS, true) => $this->profileAvailabilitySentence(),
            in_array($toolName, self::PROFILER_CONTROL_TOOLS, true) => $this->profilerActiveSentence(),
            self::RENDER_PAGE_TOOL === $toolName => $this->allowedHostsSentence(),
            default => null,
        };

        return null === $suffix ? $staticDescription : $staticDescription.' '.$suffix;
    }

    private function profileAvailabilitySentence(): string
    {
        $latest = $this->profiles->rawLatest();
        if (null === $latest) {
            return 'No profiles exist yet — run typo3-profiler-start, exercise the site, then retry.';
        }

        return sprintf('Current state: the newest recorded profile is from %s.', Cast::string($latest['time'] ?? 'an unknown time'));
    }

    private function profilerActiveSentence(): string
    {
        $status = $this->profilerState->status();
        if (!$status['active']) {
            return 'Current state: profiling is not currently active.';
        }

        return sprintf('Current state: profiling is active, %ds remaining.', $status['ttl_seconds'] ?? 0);
    }

    private function allowedHostsSentence(): string
    {
        $hosts = $this->siteHosts->hosts();
        if ([] === $hosts) {
            return 'Current state: no site configuration was found, so no host is currently allowed.';
        }

        return sprintf('Current state: the SSRF guard currently allows %s.', implode(', ', $hosts));
    }
}
