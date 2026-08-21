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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command\Support;

use KonradMichalik\Typo3AiMate\Command\Support\AgentHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AgentHarnessTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class AgentHarnessTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir().'/ai-mate-harness-'.bin2hex(random_bytes(8));
        mkdir($this->projectRoot, 0o700, true);
    }

    protected function tearDown(): void
    {
        // scandir, not glob: the markers include dotfiles.
        foreach (array_diff(scandir($this->projectRoot) ?: [], ['.', '..']) as $entry) {
            $path = $this->projectRoot.'/'.$entry;
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->projectRoot);
    }

    #[Test]
    public function claudeGetsACommandSplitFromItsArguments(): void
    {
        self::assertSame(
            ['command' => 'ddev', 'args' => ['exec', 'vendor/bin/mate', 'serve']],
            AgentHarness::Claude->serverEntry(['ddev', 'exec', 'vendor/bin/mate', 'serve']),
        );
    }

    #[Test]
    public function opencodeGetsOneArgvArrayWithTheTransportNamed(): void
    {
        self::assertSame(
            ['type' => 'local', 'command' => ['./vendor/bin/mate', 'serve'], 'enabled' => true],
            AgentHarness::Opencode->serverEntry(['./vendor/bin/mate', 'serve']),
        );
    }

    #[Test]
    public function eachHarnessNamesItsOwnFileAndSection(): void
    {
        self::assertSame('.mcp.json', AgentHarness::Claude->configFile());
        self::assertSame('mcpServers', AgentHarness::Claude->sectionKey());
        self::assertSame('opencode.json', AgentHarness::Opencode->configFile());
        self::assertSame('mcp', AgentHarness::Opencode->sectionKey());
    }

    #[Test]
    public function detectIsEmptyForAProjectWithoutAnyMarker(): void
    {
        self::assertSame([], AgentHarness::detect($this->projectRoot));
    }

    #[Test]
    public function detectRecognisesClaudeAndOpencodeMarkers(): void
    {
        touch($this->projectRoot.'/.mcp.json');

        self::assertSame([AgentHarness::Claude], AgentHarness::detect($this->projectRoot));

        touch($this->projectRoot.'/opencode.json');

        self::assertSame(
            [AgentHarness::Claude, AgentHarness::Opencode],
            AgentHarness::detect($this->projectRoot),
        );
    }

    #[Test]
    public function detectIgnoresArtifactsMateWritesForEveryHarness(): void
    {
        touch($this->projectRoot.'/AGENTS.md');
        mkdir($this->projectRoot.'/.agents');

        self::assertSame([], AgentHarness::detect($this->projectRoot));
    }
}
