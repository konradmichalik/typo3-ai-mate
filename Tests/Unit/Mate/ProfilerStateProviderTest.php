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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Mate;

use KonradMichalik\Typo3AiMate\Mate\{ProfilerStateProvider, Typo3CliRunner};
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function is_array;

/**
 * ProfilerStateProviderTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ProfilerStateProviderTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/typo3-ai-mate-state-'.bin2hex(random_bytes(8));
        mkdir($this->rootDir.'/var/log', 0777, true);
        mkdir($this->rootDir.'/vendor/bin', 0777, true);

        // Stands in for `vendor/bin/typo3`: records the argv it was called with so
        // a test can assert the forwarded command, and mimics profiler:activate by
        // writing the activation state file the provider reads back afterwards.
        file_put_contents($this->rootDir.'/vendor/bin/typo3', <<<'PHP'
            <?php
            $root = dirname(__DIR__, 2);
            file_put_contents($root.'/argv.json', json_encode(array_slice($argv, 1)));
            if ('profiler:activate' === ($argv[1] ?? null)) {
                file_put_contents($root.'/var/log/profiler-activation-state.json', json_encode(['expiresAt' => time() + 900]));
            }
            if ('profiler:deactivate' === ($argv[1] ?? null)) {
                @unlink($root.'/var/log/profiler-activation-state.json');
            }
            echo 'ok';
            PHP);
    }

    protected function tearDown(): void
    {
        foreach (['/argv.json', '/var/log/profiler-activation-state.json', '/vendor/bin/typo3'] as $file) {
            @unlink($this->rootDir.$file);
        }
        foreach (['/vendor/bin', '/vendor', '/var/log', '/var', ''] as $dir) {
            @rmdir($this->rootDir.$dir);
        }
    }

    #[Test]
    public function statusReportsInactiveWhenNoStateFileExists(): void
    {
        self::assertSame(
            ['active' => false, 'expires_at' => null, 'ttl_seconds' => null],
            $this->provider()->status(),
        );
    }

    #[Test]
    public function statusReportsActiveWithRemainingTtl(): void
    {
        $this->writeState(['expiresAt' => time() + 600]);

        $status = $this->provider()->status();

        self::assertTrue($status['active']);
        self::assertIsString($status['expires_at']);
        self::assertIsInt($status['ttl_seconds']);
        // Allow a second of drift between writing the fixture and reading it back.
        self::assertGreaterThan(595, $status['ttl_seconds']);
        self::assertLessThanOrEqual(600, $status['ttl_seconds']);
    }

    #[Test]
    public function statusReportsInactiveForAnExpiredState(): void
    {
        $this->writeState(['expiresAt' => time() - 1]);

        self::assertFalse($this->provider()->status()['active']);
    }

    #[Test]
    public function statusReportsInactiveForCorruptOrUnexpectedStateContents(): void
    {
        file_put_contents($this->stateFile(), 'not json at all');
        self::assertFalse($this->provider()->status()['active']);

        $this->writeState(['expiresAt' => 'not-an-int']);
        self::assertFalse($this->provider()->status()['active']);

        $this->writeState(['somethingElse' => 1]);
        self::assertFalse($this->provider()->status()['active']);
    }

    #[Test]
    public function activateForwardsTheDurationOptionToTheProfilerCommand(): void
    {
        $this->provider()->activate('30m');

        self::assertSame(['profiler:activate', '--duration', '30m'], $this->recordedArgv());
    }

    #[Test]
    public function activateOmitsTheDurationOptionSoTheProfilerDefaultApplies(): void
    {
        $this->provider()->activate(null);

        self::assertSame(['profiler:activate'], $this->recordedArgv());
    }

    #[Test]
    public function activateReturnsTheStateReadBackFromTheStateFile(): void
    {
        $result = $this->provider()->activate('15m');

        self::assertTrue($result['active']);
        self::assertIsInt($result['ttl_seconds']);
    }

    #[Test]
    public function activateRejectsADurationAboveTheCeilingWithoutCallingTheCommand(): void
    {
        $result = $this->provider()->activate('2h');

        self::assertIsString($result['error']);
        self::assertStringContainsString('60', $result['error']);
        self::assertFileDoesNotExist($this->rootDir.'/argv.json');
    }

    #[Test]
    public function activateRejectsAMalformedDurationWithoutCallingTheCommand(): void
    {
        $result = $this->provider()->activate('soon');

        self::assertIsString($result['error']);
        self::assertFileDoesNotExist($this->rootDir.'/argv.json');
    }

    #[Test]
    public function activateReportsACommandFailureAsAnErrorEnvelope(): void
    {
        file_put_contents($this->rootDir.'/vendor/bin/typo3', '<?php fwrite(STDERR, "boom"); exit(1);');

        $result = $this->provider()->activate('15m');

        self::assertIsString($result['error']);
        self::assertStringContainsString('boom', $result['error']);
    }

    #[Test]
    public function deactivateCallsTheProfilerCommandAndReportsTheResultingState(): void
    {
        $this->writeState(['expiresAt' => time() + 600]);

        $result = $this->provider()->deactivate();

        self::assertSame(['profiler:deactivate'], $this->recordedArgv());
        self::assertFalse($result['active']);
    }

    #[Test]
    public function deactivateReportsACommandFailureAsAnErrorEnvelope(): void
    {
        file_put_contents($this->rootDir.'/vendor/bin/typo3', '<?php fwrite(STDERR, "nope"); exit(1);');

        $result = $this->provider()->deactivate();

        self::assertIsString($result['error']);
        self::assertStringContainsString('nope', $result['error']);
    }

    private function provider(): ProfilerStateProvider
    {
        return new ProfilerStateProvider(new Typo3CliRunner($this->rootDir), $this->rootDir);
    }

    private function stateFile(): string
    {
        return $this->rootDir.'/var/log/profiler-activation-state.json';
    }

    /**
     * @param array<string, mixed> $state
     */
    private function writeState(array $state): void
    {
        file_put_contents($this->stateFile(), json_encode($state, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<mixed>
     */
    private function recordedArgv(): array
    {
        $recorded = json_decode((string) file_get_contents($this->rootDir.'/argv.json'), true);
        self::assertTrue(is_array($recorded));

        return $recorded;
    }
}
