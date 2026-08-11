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

use KonradMichalik\Typo3AiMate\Command\Support\McpJsonRegistrar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function extension_loaded;

/**
 * McpJsonRegistrarTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class McpJsonRegistrarTest extends TestCase
{
    /**
     * @var array{command: string, args: list<string>}
     */
    private const ENTRY = ['command' => './vendor/bin/mate', 'args' => ['serve']];
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/ai-mate-mcp-'.bin2hex(random_bytes(8)).'.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
    }

    #[Test]
    public function registerCreatesFileWhenMissing(): void
    {
        $result = (new McpJsonRegistrar($this->path))->register(self::ENTRY);

        self::assertSame(['action' => 'created'], $result);
        self::assertFileExists($this->path);
        self::assertSame(self::ENTRY, $this->decodeMcpServers()['typo3-ai-mate']);
    }

    #[Test]
    public function registerReportsUnchangedOnSecondRun(): void
    {
        $registrar = new McpJsonRegistrar($this->path);
        $registrar->register(self::ENTRY);

        $result = $registrar->register(self::ENTRY);

        self::assertSame(['action' => 'unchanged'], $result);
    }

    #[Test]
    public function registerReportsUpdatedWhenEntryChanges(): void
    {
        $registrar = new McpJsonRegistrar($this->path);
        $registrar->register(self::ENTRY);

        $result = $registrar->register(['command' => 'ddev', 'args' => ['exec', 'vendor/bin/mate', 'serve']]);

        self::assertSame(['action' => 'updated'], $result);
    }

    #[Test]
    public function registerPreservesForeignServerEntries(): void
    {
        file_put_contents($this->path, json_encode([
            'mcpServers' => ['symfony-ai-mate' => ['command' => 'php', 'args' => ['vendor/bin/mate', 'serve']]],
        ]));

        (new McpJsonRegistrar($this->path))->register(self::ENTRY);

        $servers = $this->decodeMcpServers();
        self::assertSame(['command' => 'php', 'args' => ['vendor/bin/mate', 'serve']], $servers['symfony-ai-mate']);
        self::assertSame(self::ENTRY, $servers['typo3-ai-mate']);
    }

    #[Test]
    public function registerAbortsOnInvalidJsonWithoutTouchingTheFile(): void
    {
        file_put_contents($this->path, '{not valid json');

        $result = (new McpJsonRegistrar($this->path))->register(self::ENTRY);

        self::assertSame('unchanged', $result['action']);
        self::assertArrayHasKey('error', $result);
        self::assertSame('{not valid json', file_get_contents($this->path));
    }

    #[Test]
    public function concurrentRegistrationsPreserveAForeignEntryAndProduceValidJson(): void
    {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped('Requires pcntl to fork a genuinely concurrent writer.');
        }

        file_put_contents($this->path, json_encode([
            'mcpServers' => ['other-server' => ['command' => 'other', 'args' => []]],
        ]));
        $lockAcquiredMarker = $this->path.'.locked';

        // Child: a stand-in for "another process editing .mcp.json at the same
        // time" - holds the exclusive lock for a moment while adding its own
        // foreign entry, so the parent's register() below is forced to wait
        // for it (and then read its result) instead of racing it. The marker
        // file lets the parent wait for the lock to actually be held instead
        // of guessing a sleep duration long enough to win the fork race.
        $pid = pcntl_fork();
        if (-1 === $pid) {
            self::fail('Could not fork a child process.');
        }

        if (0 === $pid) {
            $handle = fopen($this->path, 'c+');
            self::assertIsResource($handle);
            flock($handle, \LOCK_EX);
            touch($lockAcquiredMarker);
            usleep(150_000);
            $data = json_decode((string) stream_get_contents($handle), true);
            self::assertIsArray($data);
            $servers = $data['mcpServers'];
            self::assertIsArray($servers);
            $servers['child-server'] = ['command' => 'child', 'args' => []];
            $data['mcpServers'] = $servers;
            ftruncate($handle, 0);
            fseek($handle, 0);
            fwrite($handle, (string) json_encode($data));
            flock($handle, \LOCK_UN);
            fclose($handle);
            exit(0);
        }

        $deadline = microtime(true) + 2.0;
        while (!file_exists($lockAcquiredMarker) && microtime(true) < $deadline) {
            usleep(1_000);
        }
        self::assertFileExists($lockAcquiredMarker, 'The child never signalled that it holds the lock.');

        $start = hrtime(true);
        (new McpJsonRegistrar($this->path))->register(self::ENTRY);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        pcntl_waitpid($pid, $status);
        unlink($lockAcquiredMarker);

        self::assertGreaterThan(
            50.0,
            $elapsedMs,
            'register() returned before the concurrent writer released its lock - it raced instead of waiting for it.',
        );

        $decoded = json_decode((string) file_get_contents($this->path), true);
        self::assertIsArray($decoded);
        $servers = $decoded['mcpServers'];
        self::assertIsArray($servers);
        self::assertSame(['command' => 'other', 'args' => []], $servers['other-server']);
        self::assertSame(['command' => 'child', 'args' => []], $servers['child-server']);
        self::assertSame(self::ENTRY, $servers['typo3-ai-mate']);
    }

    #[Test]
    public function dryRunReportsActionWithoutWriting(): void
    {
        $result = (new McpJsonRegistrar($this->path))->register(self::ENTRY, dryRun: true);

        self::assertSame(['action' => 'created'], $result);
        self::assertFileDoesNotExist($this->path);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeMcpServers(): array
    {
        $decoded = json_decode((string) file_get_contents($this->path), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['mcpServers']);

        return $decoded['mcpServers'];
    }
}
