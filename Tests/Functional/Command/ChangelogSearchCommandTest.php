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

use KonradMichalik\Typo3AiMate\Command\ChangelogSearchCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * ChangelogSearchCommandTest.
 *
 * Exercises the command against a fixture changelog directory rather than
 * the real vendor/typo3/cms-core one, so the test stays independent of
 * which core version happens to be installed (the fixture mirrors the real
 * `<Type>-<issue>-<Title>.rst` layout under `Fixtures/Changelog/<version>/`).
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ChangelogSearchCommandTest extends FunctionalTestCase
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
    public function findsAMatchInTheInstalledMajorsFixtureDirectory(): void
    {
        [$exitCode, $result] = $this->runCommand(['query' => 'foobar'], 14);

        self::assertSame(0, $exitCode);
        self::assertSame('14', $result['version']);
        self::assertSame(2, $result['resultCount']);

        $results = $result['results'];
        self::assertIsArray($results);
        $first = $results[0];
        self::assertIsArray($first);
        self::assertSame('Breaking', $first['type']);
        self::assertSame(100001, $first['issue']);
        self::assertSame('14.0', $first['version']);
        self::assertSame('Remove FooBar API', $first['title']);
        self::assertStringContainsString('FooBarService', $first['excerpt']);
        self::assertSame('Documentation/Changelog/14.0/Breaking-100001-RemoveFooBarApi.rst', $first['path']);
    }

    #[Test]
    public function doesNotSeeAnotherMajorsResultsByDefault(): void
    {
        [, $result] = $this->runCommand(['query' => 'foobar'], 13);

        self::assertSame(1, $result['resultCount']);
        $results = $result['results'];
        self::assertIsArray($results);
        $first = $results[0];
        self::assertIsArray($first);
        self::assertSame('13.4', $first['version']);
    }

    #[Test]
    public function widensToBothMajorsWithAnExplicitVersionOverride(): void
    {
        [, $result] = $this->runCommand(['query' => 'foobar', '--core-version' => '1'], 14);

        self::assertSame(3, $result['resultCount']);
    }

    #[Test]
    public function filtersByType(): void
    {
        [, $result] = $this->runCommand(['query' => 'foobar', '--type' => 'Feature'], 14);

        self::assertSame(1, $result['resultCount']);
        $results = $result['results'];
        self::assertIsArray($results);
        $first = $results[0];
        self::assertIsArray($first);
        self::assertSame('Feature', $first['type']);
    }

    #[Test]
    public function fallsBackToTheInstalledCoresOwnChangelogDirectory(): void
    {
        // Constructed without a path override, which is how the command runs in
        // production: it has to find typo3/cms-core's Documentation/Changelog on
        // its own rather than being told where it is.
        $command = new ChangelogSearchCommand($this->get(Typo3Version::class));
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['query' => 'sys_file_reference', '--limit' => '1']);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertArrayHasKey('results', $result);
    }

    #[Test]
    public function skipsAChangelogFileThatDoesNotCarryEveryQueryWord(): void
    {
        // Every word has to appear in the same file. A query pairing a word from
        // the fixture with one that appears nowhere must return nothing, rather
        // than matching on the first word alone.
        [$exitCode, $result] = $this->runCommand(['query' => 'FooBar wordthatappearsinnofixture'], 13);

        self::assertSame(0, $exitCode);
        self::assertSame([], $result['results']);
    }

    #[Test]
    public function failsWithAnActionableErrorWhenTheChangelogDirectoryIsMissing(): void
    {
        $command = new ChangelogSearchCommand($this->get(Typo3Version::class), __DIR__.'/../Fixtures/DoesNotExist');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['query' => 'foobar']);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame(
            'typo3/cms-core does not ship a Documentation/Changelog directory in this installation.',
            $result['error'],
        );
    }

    /**
     * @param array<string, string> $input
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runCommand(array $input, int $majorVersion): array
    {
        $typo3Version = $majorVersion === $this->get(Typo3Version::class)->getMajorVersion()
            ? $this->get(Typo3Version::class)
            : new class($majorVersion) extends Typo3Version {
                public function __construct(private readonly int $major) {}

                public function getMajorVersion(): int
                {
                    return $this->major;
                }
            };

        $command = new ChangelogSearchCommand($typo3Version, __DIR__.'/../Fixtures/Changelog');
        $tester = new CommandTester($command);
        $exitCode = $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded, 'Command output is valid JSON.');

        return [$exitCode, $decoded];
    }
}
