<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_ai_mate" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <km@move-elevator.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Mate;

use KonradMichalik\Typo3AiMate\Mate\Typo3CliRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Typo3CliRunnerTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final class Typo3CliRunnerTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/typo3-ai-mate-'.bin2hex(random_bytes(8));
        mkdir($this->rootDir.'/vendor/bin', 0777, true);

        // Fake CLI: branches on the first argument (the command name).
        $script = <<<'PHP'
            <?php
            $command = $argv[1] ?? '';
            switch ($command) {
                case 'ok':       echo json_encode(['hello' => 'world', 'args' => array_slice($argv, 2)]); break;
                case 'list':     echo json_encode(['a', 'b', 'c']); break;
                case 'notjson':  echo 'this is not json'; break;
                case 'warned':   echo "PHP Warning:  Undefined array key \"encryptionKey\" in HashService.php on line 43\n"; echo json_encode(['extension' => 'ext', 'matches' => []]); break;
                case 'errfield': echo json_encode(['error' => 'something broke']); break;
                case 'fail':     fwrite(STDERR, 'boom'); exit(1);
            }
            PHP;
        file_put_contents($this->rootDir.'/vendor/bin/typo3', $script);
    }

    protected function tearDown(): void
    {
        @unlink($this->rootDir.'/vendor/bin/typo3');
        @rmdir($this->rootDir.'/vendor/bin');
        @rmdir($this->rootDir.'/vendor');
        @rmdir($this->rootDir);
    }

    #[Test]
    public function jsonDecodesStdoutObjectAndForwardsArguments(): void
    {
        $result = (new Typo3CliRunner($this->rootDir))->json('ok', ['tt_content'], ['list' => true, 'skip' => false]);

        self::assertSame('world', $result['hello']);
        self::assertSame(['tt_content', '--list'], $result['args']);
    }

    #[Test]
    public function jsonDecodesStdoutList(): void
    {
        self::assertSame(['a', 'b', 'c'], (new Typo3CliRunner($this->rootDir))->json('list'));
    }

    #[Test]
    public function jsonToleratesPhpWarningsPrintedBeforeTheJsonDocument(): void
    {
        $result = (new Typo3CliRunner($this->rootDir))->json('warned');

        self::assertSame('ext', $result['extension']);
        self::assertSame([], $result['matches']);
    }

    #[Test]
    public function jsonThrowsOnInvalidJson(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/did not return valid JSON/');

        (new Typo3CliRunner($this->rootDir))->json('notjson');
    }

    #[Test]
    public function jsonThrowsWhenCommandReportsErrorField(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/something broke/');

        (new Typo3CliRunner($this->rootDir))->json('errfield');
    }

    #[Test]
    public function jsonThrowsOnNonZeroExitCode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/failed \(exit 1\): boom/');

        (new Typo3CliRunner($this->rootDir))->json('fail');
    }

    #[Test]
    public function jsonOrErrorReturnsDecodedDataOnSuccess(): void
    {
        $result = (new Typo3CliRunner($this->rootDir))->jsonOrError('ok');

        self::assertSame('world', $result['hello']);
    }

    #[Test]
    public function jsonOrErrorReturnsAnErrorEnvelopeInsteadOfThrowing(): void
    {
        $result = (new Typo3CliRunner($this->rootDir))->jsonOrError('fail');

        self::assertArrayHasKey('error', $result);
        self::assertIsString($result['error']);
        self::assertStringContainsString('failed (exit 1): boom', $result['error']);
    }
}
