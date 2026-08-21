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

namespace KonradMichalik\Typo3AiMate\Command\Support;

/**
 * DdevEnvironment.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class DdevEnvironment
{
    private function __construct(
        public bool $isDdevProject,
        public bool $isInsideContainer,
    ) {}

    /**
     * `.ddev/config.yaml` is part of the repository and therefore visible at the
     * same project-relative path whether this runs on the host or inside the web
     * container (DDEV bind-mounts the project root unchanged) — one check covers
     * both. `IS_DDEV_PROJECT` is set by DDEV's web container image and is only
     * used to decide what to *tell* the user, never which launch command to
     * register: an MCP client always runs on the host, so a DDEV project always
     * needs the `ddev exec` form regardless of where this command itself runs.
     */
    public static function detect(string $projectRoot, ?string $insideContainerEnvValue = null): self
    {
        return new self(
            is_file($projectRoot.'/.ddev/config.yaml'),
            'true' === ($insideContainerEnvValue ?? getenv('IS_DDEV_PROJECT')),
        );
    }

    /**
     * The launch command as one argv list, program first. Harnesses disagree on
     * whether they want it split (Claude Code) or whole (opencode), so the split
     * happens where the entry is built.
     *
     * @return list<string>
     */
    public function mcpServerLaunchArgv(): array
    {
        return $this->isDdevProject
            ? ['ddev', 'exec', 'vendor/bin/mate', 'serve']
            : ['./vendor/bin/mate', 'serve'];
    }
}
