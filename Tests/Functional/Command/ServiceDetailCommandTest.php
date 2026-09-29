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

namespace KonradMichalik\Typo3AiMate\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * ServiceDetailCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ServiceDetailCommandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    #[Test]
    public function resolvesAPubliclyAliasedCoreInterfaceToItsRealImplementation(): void
    {
        [$exitCode, $result] = $this->runCommand(['id' => 'Psr\Clock\ClockInterface']);

        self::assertSame(0, $exitCode);
        self::assertSame('Psr\Clock\ClockInterface', $result['id']);
        self::assertSame('TYPO3\CMS\Core\Clock\SystemClock', $result['class']);
        self::assertTrue($result['shared']);
        self::assertSame([], $result['constructorArguments']);
    }

    #[Test]
    public function reportsConstructorArgumentsOfARealServiceInThisExtension(): void
    {
        // Autoconfigure/#[AsCommand] force console commands public (see the next test's comment),
        // which doubles as a real-world "why is X injected here" example against our own code.
        [$exitCode, $result] = $this->runCommand(['id' => 'KonradMichalik\Typo3AiMate\Command\ExtensionScannerCommand']);

        self::assertSame(0, $exitCode);
        self::assertSame('KonradMichalik\Typo3AiMate\Command\ExtensionScannerCommand', $result['class']);
        self::assertSame([
            ['position' => 0, 'name' => 'packageManager', 'type' => 'TYPO3\CMS\Core\Package\PackageManager'],
        ], $result['constructorArguments']);
    }

    #[Test]
    public function failsReadablyForAPrivateOrUnknownService(): void
    {
        // Autoconfigure/#[AsCommand] force console commands public so CommandRegistry can fetch
        // them by id, so a plain support service is the genuinely private case: registered by
        // Configuration/Services.yaml's default (public: false), never tagged public elsewhere.
        [$exitCode, $result] = $this->runCommand(['id' => 'KonradMichalik\Typo3AiMate\Service\FluidResolver']);

        self::assertSame(1, $exitCode);
        self::assertSame(
            'Service "KonradMichalik\Typo3AiMate\Service\FluidResolver" is not registered or not public. Only public services can be inspected; TYPO3 makes most services private by default.',
            $result['error'],
        );
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:service:detail');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
