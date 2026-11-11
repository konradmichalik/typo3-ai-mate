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
 * OverridesTool.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final readonly class OverridesTool
{
    public function __construct(private Typo3CliRunner $typo3) {}

    /**
     * @param string|null $class fully qualified class name, matched against both the original and the replacement class of every XCLASS registration, e.g. TYPO3\CMS\Core\Http\RequestFactory; omit to list all registrations
     */
    #[MateTool(
        name: 'typo3-overrides',
        title: 'TYPO3 Class Overrides (XCLASS)',
        description: 'XCLASS registrations from SYS/Objects: original class, replacement class, the class effectively instantiated when XCLASSes chain, and the extension owning the replacement. Answers "why does my change to class X not take effect". The owner is the active extension the replacement class belongs to; ownerDeterminedBy says how it was found: classFile (the file the autoloader resolves lies inside that extension) or namespace (fallback: the replacement matches a PSR-4 namespace from the extension\'s composer.json). TYPO3 does not record which ext_localconf.php wrote a registration, so this is the replacement\'s owner, not provably the registering extension. Pass class to check one class from either side: found=false means no XCLASS touches it. DI service decorators are not covered: the compiled container keeps no mapping from a decorated service id to its decorator, recovering it would mean rebuilding the container, which this read-only tool does not do. Use typo3-service to see which class an injected public service actually resolves to.',
    )]
    public function list(?string $class = null): string
    {
        $options = null !== $class && '' !== $class ? ['class' => $class] : [];

        return ToolResult::untrusted($this->typo3->jsonOrError('typo3-ai-mate:overrides:list', [], $options));
    }
}
