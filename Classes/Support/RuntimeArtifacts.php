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

namespace KonradMichalik\Typo3AiMate\Support;

/**
 * RuntimeArtifacts.
 *
 * Boot-free existence checks on what an installation has written under
 * `var/log`. Cheap enough to run while the Mate server starts, so the tool
 * surface can be decided from them ({@see ToolClusterGate}), and available to
 * the TYPO3 process too so `typo3-info` can report the same state. It
 * deliberately reads and parses nothing: that is the log and profiler tools'
 * job.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class RuntimeArtifacts
{
    public function __construct(private string $rootDir) {}

    public function hasLogEntries(): bool
    {
        foreach (glob($this->rootDir.'/var/log/*.log') ?: [] as $file) {
            if (is_file($file) && filesize($file) > 0) {
                return true;
            }
        }

        return false;
    }

    public function hasProfiles(): bool
    {
        return [] !== (glob($this->rootDir.'/var/log/profiles/*.json') ?: []);
    }
}
