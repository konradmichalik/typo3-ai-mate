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

use KonradMichalik\Ttt\Assertion\JsonAssertions;
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
    use JsonAssertions;

    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/typo3-ai-mate-ctrl-'.bin2hex(random_bytes(8));
        mkdir($this->rootDir.'/var/log', 0777, true);
        mkdir($this->rootDir.'/vendor/bin', 0777, true);
        // Records every invocation, so a test can assert that a tool did *not*
        // shell out, and mimics the profiler commands' effect on the state file.
        file_put_contents($this->rootDir.'/vendor/bin/typo3', <<<'PHP'
            <?php
            $root = dirname(__DIR__, 2);
            file_put_contents($root.'/cli-invoked', $argv[1] ?? '');
            $state = $root.'/var/log/profiler-activation-state.json';
            'profiler:activate' === ($argv[1] ?? null)
                ? file_put_contents($state, json_encode(['expiresAt' => time() + 900]))
                : @unlink($state);
            echo 'ok';
            PHP);
    }

    protected function tearDown(): void
    {
        foreach (['/var/log/profiler-activation-state.json', '/vendor/bin/typo3', '/cli-invoked'] as $file) {
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

        self::assertJsonPath($result, 'active', true);
        self::assertJsonHasPath($result, 'expires_at');
    }

    #[Test]
    public function startEncodesTheErrorEnvelopeForARejectedDuration(): void
    {
        $result = $this->decode($this->tool()->start('99h'));

        self::assertJsonHasPath($result, 'error');
    }

    #[Test]
    public function stopEncodesTheDeactivatedState(): void
    {
        // Starting from an *active* state, so the reported "false" can only come
        // from the command having run — not from there being nothing to clear.
        $this->writeActiveState();

        $result = $this->decode($this->tool()->stop());

        self::assertJsonPath($result, 'active', false);
        self::assertStringEqualsFile($this->rootDir.'/cli-invoked', 'profiler:deactivate');
    }

    #[Test]
    public function statusEncodesTheCurrentStateWithoutInvokingTheCommand(): void
    {
        $this->writeActiveState();

        $result = $this->decode($this->tool()->status());

        // Reads the seeded state as-is, and the absent marker proves it got there
        // without shelling out — a CLI round trip would have cleared the state.
        self::assertJsonPath($result, 'active', true);
        self::assertFileDoesNotExist($this->rootDir.'/cli-invoked');
    }

    private function writeActiveState(): void
    {
        file_put_contents(
            $this->rootDir.'/var/log/profiler-activation-state.json',
            json_encode(['expiresAt' => time() + 600], \JSON_THROW_ON_ERROR),
        );
    }

    private function tool(): ProfilerControlTool
    {
        return new ProfilerControlTool(
            new ProfilerStateProvider(new Typo3CliRunner($this->rootDir), $this->rootDir),
        );
    }
}
