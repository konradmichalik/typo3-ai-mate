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
 * UpgradeCompatibilityTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class UpgradeCompatibilityTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string $target target TYPO3 version: major.minor (14.3, any patch release) or a full version (14.3.7)
     */
    #[MateTool(
        name: 'typo3-upgrade-compatibility',
        title: 'TYPO3 Upgrade Compatibility',
        description: 'Which installed extensions block an upgrade to a target TYPO3 version. For every active non-core extension: its typo3/cms-core constraint (from composer.json, or ext_emconf.php depends.typo3 when composer.json has none), the source it came from, origin own vs. thirdParty, and status blocking | unknown | compatible against target. Blocking extensions are listed first; an extension without any constraint is unknown, never compatible. Offline: reads installed package metadata only, no registry lookup, so it cannot say whether a newer release of a blocking extension exists. Answers which packages do not even allow the target; typo3-extension-scanner answers which code has to change.',
    )]
    public function check(string $target): string
    {
        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:upgrade:compatibility', [], ['target' => $target]));
    }
}
