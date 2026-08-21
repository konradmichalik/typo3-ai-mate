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
 * InfoCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class InfoCommandTest extends FunctionalTestCase
{
    // EXT:install provides LateBootService (autowired by UpgradeWizardsCommand),
    // which the extension's service definitions require to compile.
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    #[Test]
    public function reportsVersionPhpContextDatabaseExtensionsPackagesProfilerAndContentTypeCount(): void
    {
        $result = $this->runCommand();

        $typo3 = $result['typo3'];
        self::assertIsArray($typo3);
        self::assertIsString($typo3['version']);
        self::assertIsInt($typo3['majorVersion']);
        self::assertContains($typo3['majorVersion'], [13, 14]);

        self::assertSame(\PHP_VERSION, $result['php']);
        self::assertIsString($result['context']);

        $database = $result['database'];
        self::assertIsArray($database);
        self::assertNotSame('', $database['platform']);
        self::assertNotSame('', $database['version']);

        $extensions = $result['extensions'];
        self::assertIsArray($extensions);
        self::assertContains('typo3_ai_mate', $extensions['own']);

        $packages = $result['packages'];
        self::assertIsArray($packages);
        self::assertArrayHasKey('konradmichalik/typo3-ai-mate', $packages);
        self::assertArrayHasKey('symfony/ai-mate', $packages);

        $profiler = $result['profiler'];
        self::assertIsArray($profiler);
        self::assertIsBool($profiler['cliAvailable']);
        self::assertIsBool($profiler['activationWindowOpen']);
        self::assertIsBool($profiler['developmentContext']);

        // Availability, not registration: this command runs in its own process
        // and evaluates the state now, while the MCP session fixed its tool list
        // when it started.
        $toolClusters = (array) $result['toolClusters'];
        foreach (['profiler', 'logs'] as $cluster) {
            $state = (array) $toolClusters[$cluster];
            self::assertIsBool($state['available']);
            self::assertNotSame('', $state['reason']);
        }
        self::assertStringContainsString('reconnect', (string) $toolClusters['_hint']);

        // The catalogue itself stays behind the flag; the entry point only says
        // how many there are.
        $contentTypes = $result['contentTypes'];
        self::assertIsArray($contentTypes);
        self::assertGreaterThan(0, $contentTypes['cTypeCount']);
        self::assertArrayNotHasKey('cTypes', $contentTypes);
        self::assertArrayHasKey('_hint', $contentTypes);
    }

    #[Test]
    public function reportsTheContentTypeCatalogueOnRequest(): void
    {
        $result = $this->runCommand(['--content-types' => true]);

        $contentTypes = $result['contentTypes'];
        self::assertIsArray($contentTypes);
        $cTypes = $contentTypes['cTypes'];
        self::assertIsArray($cTypes);
        self::assertNotSame([], $cTypes);
        $values = array_column($cTypes, 'value');
        self::assertContains('header', $values);
        // Real core labels are resolved, not left as raw LLL: references.
        foreach ($cTypes as $cType) {
            self::assertIsArray($cType);
            self::assertIsString($cType['label']);
            self::assertStringStartsNotWith('LLL:', $cType['label']);
        }
    }

    /**
     * @param array<string, bool> $input
     *
     * @return array<string, mixed>
     */
    private function runCommand(array $input = []): array
    {
        $command = $this->get(CommandRegistry::class)->get('typo3-ai-mate:info:dump');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        self::assertSame(0, $exitCode);
        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return $decoded;
    }
}
