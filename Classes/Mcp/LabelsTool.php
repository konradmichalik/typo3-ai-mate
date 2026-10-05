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
 * LabelsTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class LabelsTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string|null $references Comma-separated label references to check in one call, e.g. LLL:EXT:my_ext/Resources/Private/Language/locallang.xlf:plugin.title; on v14 also translation domains such as core.common:cancel. A missing key carries the closest keys of the same file as suggestions
     * @param string|null $locale     also report whether each label is translated into this locale, e.g. de; omit to check the default language only
     * @param string|null $extension  extension key whose label files to list (key count, translation locales, v14 domain); used when references is omitted
     */
    #[MateTool(
        name: 'typo3-labels',
        title: 'TYPO3 Labels',
        description: 'Whether a label reference resolves to a defined key in this installation, with its default text. exists=false is the answer, not an empty result: an LLL: reference to an undefined key renders as an empty string. Pass several references at once; a missing key carries the closest keys of the same file as suggestions, a missing file the label files the extension actually has. Pass locale to see whether each label is translated or falls back to the default text. Labels are read through the LocalizationFactory, so locallangXMLOverride overrides apply. With extension instead of references you get that extension\'s label files with their key count and translation locales — do not grep Resources/Private/Language for this.',
    )]
    public function lookup(?string $references = null, ?string $locale = null, ?string $extension = null): string
    {
        $options = array_filter(
            ['references' => $references, 'locale' => $locale, 'extension' => $extension],
            static fn (?string $value): bool => null !== $value && '' !== $value,
        );

        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:labels:lookup', [], $options));
    }
}
