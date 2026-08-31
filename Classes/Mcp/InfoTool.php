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
 * InfoTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class InfoTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param bool $contentTypes include the full tt_content type catalogue with labels instead of only how many there are
     */
    #[MateTool(
        name: 'typo3-info',
        title: 'TYPO3 Info',
        description: 'Call this first. The session entry point: exact TYPO3 version and major (v13 vs v14 governs almost every other recommendation), PHP version, application context (always "Development" here — every typo3_ai_mate command forces that context as a Production safety gate, not the installation\'s real configured one), database platform/version, active extensions split into own vs. third-party, relevant package versions, profiler CLI availability/version plus activationWindowOpen/developmentContext (either independently enables profiling; a per-request header can too and isn\'t reflected by either flag), toolClusters — which tool clusters the current runtime state makes available and why, since a cluster whose subject does not exist yet (no recorded profile, an empty log) collapses to its entry-point tool; this is availability as probed now, not this session\'s registry, so a cluster that has become available since the server started is offered after a reconnect, and how many tt_content types are registered. Pass contentTypes=true for the catalogue itself (CTypes with labels, plus list_type plugins on v13 only); it is two thirds of this response and overlaps with recordTypes from typo3-tca. Read this before anything else to avoid guessing from composer.json, which fails on this package\'s own constraint style (^13.4 || ^14.3).',
    )]
    public function dump(bool $contentTypes = false): string
    {
        $options = $contentTypes ? ['content-types' => true] : [];

        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:info:dump', [], $options));
    }
}
