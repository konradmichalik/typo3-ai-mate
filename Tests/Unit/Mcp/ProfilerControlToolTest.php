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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Mcp;

use KonradMichalik\Typo3AiMate\Mate\{ProfilerStateProvider, Typo3CliRunner};
use KonradMichalik\Typo3AiMate\Mcp\ProfilerControlTool;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ProfilerControlToolTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ProfilerControlToolTest extends TestCase
{
    use DecodesResponses;

    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/typo3-ai-mate-ctrl-'.bin2hex(random_bytes(8));
        mkdir($this->rootDir.'/var/log', 0777, true);
        mkdir($this->rootDir.'/vendor/bin', 0777, true);
        file_put_contents($this->rootDir.'/vendor/bin/typo3', <<<'PHP'
            <?php
            $state = dirname(__DIR__, 2).'/var/log/profiler-activation-state.json';
            'profiler:activate' === ($argv[1] ?? null)
                ? file_put_contents($state, json_encode(['expiresAt' => time() + 900]))
                : @unlink($state);
            echo 'ok';
            PHP);
    }

    protected function tearDown(): void
    {
        foreach (['/var/log/profiler-activation-state.json', '/vendor/bin/typo3'] as $file) {
            @unlink($this->rootDir.$file);
        }
        foreach (['/vendor/bin', '/vendor', '/var/log', '/var', ''] as $dir) {
            @rmdir($this->rootDir.$dir);
        }
    }

    #[Test]
    public function startEncodesTheActivatedState(): void
    {
        $result = $this->decode($this->tool()->start('15m'));

        self::assertTrue($result['active']);
        self::assertIsString($result['expires_at']);
    }

    #[Test]
    public function startEncodesTheErrorEnvelopeForARejectedDuration(): void
    {
        $result = $this->decode($this->tool()->start('99h'));

        self::assertIsString($result['error']);
    }

    #[Test]
    public function stopEncodesTheDeactivatedState(): void
    {
        $result = $this->decode($this->tool()->stop());

        self::assertFalse($result['active']);
    }

    #[Test]
    public function statusEncodesTheCurrentStateWithoutInvokingTheCommand(): void
    {
        // No state file written, and the fake binary would have created one only
        // for profiler:activate — an "inactive" answer proves status reads the file.
        $result = $this->decode($this->tool()->status());

        self::assertFalse($result['active']);
        self::assertNull($result['ttl_seconds']);
    }

    private function tool(): ProfilerControlTool
    {
        return new ProfilerControlTool(
            new ProfilerStateProvider(new Typo3CliRunner($this->rootDir), $this->rootDir),
        );
    }
}
