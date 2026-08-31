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

use InvalidArgumentException;
use JsonException;
use KonradMichalik\Typo3RequestProfiler\Activation\Duration;
use RuntimeException;
use Throwable;

use function is_array;
use function is_int;
use function sprintf;

/**
 * ProfilerStateProvider.
 *
 * Reads and toggles the request profiler's activation state.
 *
 * Split across two mechanisms on purpose. The profiler's ProfilerStateService
 * needs a booted TYPO3 (Environment::getVarPath(), GeneralUtility), which the
 * Mate process deliberately does not have — so writes go through the profiler's
 * own console commands, keeping atomic write and permission handling in the
 * package that owns them. Reads go straight to the state file, which is
 * boot-free and spares status checks a full TYPO3 bootstrap.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ProfilerStateProvider
{
    private const ACTIVATE_COMMAND = 'profiler:activate';
    private const DEACTIVATE_COMMAND = 'profiler:deactivate';
    private const STATE_FILE = '/var/log/profiler-activation-state.json';

    /**
     * Stricter than the profiler's own 7-day limit: profiling every request is
     * expensive, and an agent that enables it should not be able to leave it on
     * beyond the session it is debugging.
     */
    private const MAX_DURATION_SECONDS = 3600;

    public function __construct(
        private Typo3CliRunner $runner,
        private string $rootDir,
    ) {}

    /**
     * @param string|null $duration profiler duration string (e.g. "15m"); null applies the profiler's own default
     *
     * @return array<string, mixed> the resulting state, or an {"error": "..."} envelope
     */
    public function activate(?string $duration): array
    {
        $options = [];

        if (null !== $duration && '' !== $duration) {
            try {
                // Reuse the profiler's parser rather than reimplementing its
                // duration grammar; it is plain PHP and needs no TYPO3 boot.
                $seconds = Duration::fromString($duration)->seconds();
            } catch (InvalidArgumentException $exception) {
                return ['error' => $exception->getMessage()];
                // @codeCoverageIgnoreStart
            } catch (Throwable $exception) {
                // Only reachable on an installation whose profiler predates 0.5 and
                // therefore ships no Duration class at all, which the composer
                // constraint excludes and a test cannot construct.
                // An installation carrying a profiler older than 0.5 has no
                // Duration class at all. Report that as a readable cause rather
                // than letting a fatal Error surface as an opaque MCP error.
                return ['error' => sprintf(
                    'Cannot validate the duration (%s). Check that konradmichalik/typo3-request-profiler ^0.5 is installed in this instance.',
                    $exception->getMessage(),
                )];
                // @codeCoverageIgnoreEnd
            }

            if ($seconds > self::MAX_DURATION_SECONDS) {
                return ['error' => sprintf(
                    'Duration "%s" exceeds the %d minute ceiling enforced by ai-mate. Activate profiling for a shorter window and repeat if needed.',
                    $duration,
                    intdiv(self::MAX_DURATION_SECONDS, 60),
                )];
            }

            $options['duration'] = $duration;
        }

        try {
            $this->runner->run(self::ACTIVATE_COMMAND, [], $options);
        } catch (RuntimeException $exception) {
            return ['error' => $exception->getMessage()];
        }

        // Report the state file rather than the command's prose output, so the
        // expiry an agent sees is the one the profiler actually persisted.
        return $this->status();
    }

    /**
     * @return array<string, mixed> the resulting state, or an {"error": "..."} envelope
     */
    public function deactivate(): array
    {
        try {
            $this->runner->run(self::DEACTIVATE_COMMAND);
        } catch (RuntimeException $exception) {
            return ['error' => $exception->getMessage()];
        }

        return $this->status();
    }

    /**
     * The state-file toggle only. Profiling may additionally be active through
     * the Development context or a per-request header, neither of which is
     * visible without booting TYPO3; a profile's activation_mode records which
     * mode actually applied.
     *
     * @return array{active: bool, expires_at: string|null, ttl_seconds: int|null}
     */
    public function status(): array
    {
        $expiresAt = $this->readExpiry();
        $now = time();

        if (null === $expiresAt || $expiresAt <= $now) {
            return ['active' => false, 'expires_at' => null, 'ttl_seconds' => null];
        }

        return [
            'active' => true,
            'expires_at' => date('c', $expiresAt),
            'ttl_seconds' => $expiresAt - $now,
        ];
    }

    /**
     * Mirrors the profiler's own tolerance: anything unreadable, non-JSON or
     * without an integer expiry counts as "not activated" rather than an error.
     */
    private function readExpiry(): ?int
    {
        $contents = @file_get_contents($this->rootDir.self::STATE_FILE);
        if (false === $contents) {
            return null;
        }

        try {
            $data = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($data) || !is_int($data['expiresAt'] ?? null)) {
            return null;
        }

        return $data['expiresAt'];
    }
}
