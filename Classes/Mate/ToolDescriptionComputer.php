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

use KonradMichalik\Typo3AiMate\Support\{Cast, RuntimeArtifacts, ToolClusterGate};

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
 * @license GPL-2.0-or-later
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

    /**
     * Read once here rather than per matching tool: a discovery pass calls
     * {@see compute()} for every discovered tool, and without this, four
     * profile-read tools and three profiler-control tools would each trigger
     * their own filesystem read of the same, unchanged state.
     */
    private string $profileAvailabilitySentence;
    private string $profilerActiveSentence;
    private string $allowedHostsSentence;

    private bool $profilerSuppressed;
    private bool $logsSuppressed;

    /**
     * @var list<string>
     */
    private array $suppressedTools;

    public function __construct(
        private ProfileProvider $profiles,
        private ProfilerStateProvider $profilerState,
        private SiteHostsProvider $siteHosts,
        private RuntimeArtifacts $artifacts,
    ) {
        $this->profileAvailabilitySentence = $this->buildProfileAvailabilitySentence();
        $this->profilerActiveSentence = $this->buildProfilerActiveSentence();
        $this->allowedHostsSentence = $this->buildAllowedHostsSentence();
        $this->profilerSuppressed = !ToolClusterGate::profiler(
            null !== $this->profiles->rawLatest(),
            true === $this->profilerState->status()['active'],
        )['registered'];
        $this->logsSuppressed = !ToolClusterGate::logs($this->artifacts->hasLogEntries())['registered'];
        $this->suppressedTools = [
            ...$this->profilerSuppressed ? ToolClusterGate::PROFILER_TOOLS : [],
            ...$this->logsSuppressed ? ToolClusterGate::LOGS_TOOLS : [],
        ];
    }

    /**
     * Tools whose cluster has nothing to report yet and is therefore not put in
     * front of the model at all. Decided from the same state as the description
     * suffixes, and read here so the filesystem probes happen once per server
     * start rather than once per consumer.
     *
     * @return list<string>
     */
    public function suppressedTools(): array
    {
        return $this->suppressedTools;
    }

    public function compute(string $toolName, string $staticDescription): string
    {
        $suffix = match (true) {
            in_array($toolName, self::PROFILE_READ_TOOLS, true) => $this->profileAvailabilitySentence,
            in_array($toolName, self::PROFILER_CONTROL_TOOLS, true) => $this->profilerActiveSentence,
            self::RENDER_PAGE_TOOL === $toolName => $this->allowedHostsSentence,
            default => null,
        };
        $parts = [$staticDescription, $suffix ?? '', $this->gateNotice($toolName)];

        return implode(' ', array_filter($parts, static fn (string $part): bool => '' !== $part));
    }

    /**
     * The entry-point tool of a suppressed cluster says what is missing and how
     * to get the rest back, so a shrunken tool surface is stated rather than
     * looking like a broken install.
     */
    private function gateNotice(string $toolName): string
    {
        if ($this->profilerSuppressed && ToolClusterGate::PROFILER_ENTRY_TOOL === $toolName) {
            return 'The profile-reading tools are not registered in this session because nothing has been recorded yet: start profiling, exercise the site, then reconnect to get them.';
        }
        if ($this->logsSuppressed && ToolClusterGate::LOGS_ENTRY_TOOL === $toolName) {
            return 'The log is empty, so typo3-logs-search and typo3-logs-by-level are not registered in this session — there is nothing for them to search.';
        }

        return '';
    }

    private function buildProfileAvailabilitySentence(): string
    {
        $latest = $this->profiles->rawLatest();
        if (null === $latest) {
            return 'No profiles exist yet — run typo3-profiler-start, exercise the site, then retry.';
        }

        return sprintf('Current state: the newest recorded profile is from %s.', Cast::string($latest['time'] ?? 'an unknown time'));
    }

    private function buildProfilerActiveSentence(): string
    {
        $status = $this->profilerState->status();
        if (!$status['active']) {
            return 'Current state: profiling is not currently active.';
        }

        return sprintf('Current state: profiling is active, %ds remaining.', $status['ttl_seconds'] ?? 0);
    }

    private function buildAllowedHostsSentence(): string
    {
        $hosts = $this->siteHosts->hosts();
        if ([] === $hosts) {
            return 'Current state: no site configuration was found, so no host is currently allowed.';
        }

        return sprintf('Current state: the SSRF guard currently allows %s.', implode(', ', $hosts));
    }
}
