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
