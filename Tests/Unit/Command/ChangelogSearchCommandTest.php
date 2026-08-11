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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command;

use KonradMichalik\Ttt\Attribute\WithEnvironment;
use KonradMichalik\Typo3AiMate\Command\ChangelogSearchCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Information\Typo3Version;

use function sprintf;

/**
 * ChangelogSearchCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
#[WithEnvironment]
final class ChangelogSearchCommandTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir().'/typo3-ai-mate-changelog-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->fixtureDir);
    }

    #[Test]
    public function queryWordsLowercasesAndSplitsOnWhitespace(): void
    {
        self::assertSame(['sys_file_reference', 'uid'], $this->command()->queryWords('  SYS_FILE_REFERENCE   uid '));
    }

    #[Test]
    public function queryWordsIsEmptyForABlankQuery(): void
    {
        self::assertSame([], $this->command()->queryWords('   '));
    }

    #[Test]
    public function matchesAllWordsRequiresEveryWordToBePresent(): void
    {
        $command = $this->command();

        self::assertTrue($command->matchesAllWords('the quick brown fox', ['quick', 'fox']));
        self::assertFalse($command->matchesAllWords('the quick brown fox', ['quick', 'dog']));
    }

    #[Test]
    public function matchesAllWordsIsFalseForAnEmptyWordList(): void
    {
        self::assertFalse($this->command()->matchesAllWords('anything', []));
    }

    #[Test]
    public function parseFilenameExtractsTypeAndIssueNumber(): void
    {
        self::assertSame(
            ['type' => 'Breaking', 'issue' => 108304],
            $this->command()->parseFilename('Breaking-108304-PopulateExtensionTitleFromComposerJson.rst'),
        );
    }

    #[Test]
    public function parseFilenameReturnsNullForAnUnrecognisedFilename(): void
    {
        self::assertNull($this->command()->parseFilename('Howto.rst'));
    }

    #[Test]
    public function extractTitleReadsTheHumanReadableHeadline(): void
    {
        $content = <<<'RST'
            ..  include:: /Includes.rst.txt

            ..  _breaking-108304-1764058005:

            ===============================================================
            Breaking: #108304 - Populate extension title from composer.json
            ===============================================================

            See :issue:`108304`
            RST;

        self::assertSame('Populate extension title from composer.json', $this->command()->extractTitle($content));
    }

    #[Test]
    public function extractTitleReturnsNullWhenNoHeadlineIsFound(): void
    {
        self::assertNull($this->command()->extractTitle('no headline here'));
    }

    #[Test]
    public function excerptWindowsAroundTheFirstMatchAndMarksTruncation(): void
    {
        $content = str_repeat('x', 300).'NEEDLE'.str_repeat('y', 300);

        $excerpt = $this->command()->excerpt($content, ['needle']);

        self::assertStringContainsString('NEEDLE', $excerpt);
        self::assertStringStartsWith('…', $excerpt);
        self::assertStringEndsWith('…', $excerpt);
    }

    #[Test]
    public function excerptDoesNotPrefixWhenTheMatchIsNearTheStart(): void
    {
        $content = 'NEEDLE'.str_repeat('y', 300);

        $excerpt = $this->command()->excerpt($content, ['needle']);

        self::assertStringStartsNotWith('…', $excerpt);
    }

    #[Test]
    public function executeFindsAMatchAndScoresFilenameHitsAboveContentOnlyHits(): void
    {
        $this->writeFixture('14.0', 'Breaking-100001-RemoveFooBar.rst', 'Breaking: #100001 - Remove FooBar'."\n\nSee :issue:`100001`\n\nDescription\n===========\n\nFooBar was removed.\n");
        $this->writeFixture('14.0', 'Feature-100002-AddSomethingElse.rst', 'Feature: #100002 - Add something else'."\n\nSee :issue:`100002`\n\nDescription\n===========\n\nMentions FooBar in passing.\n");

        $tester = new CommandTester($this->command());
        $exitCode = $tester->execute(['query' => 'foobar']);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame(2, $result['resultCount']);
        $results = $result['results'];
        self::assertIsArray($results);
        // The filename-match ("RemoveFooBar") outranks the content-only match.
        $first = $this->resultAt($results, 0);
        self::assertSame('Breaking', $first['type']);
        self::assertSame(100001, $first['issue']);
        self::assertSame('Feature', $this->resultAt($results, 1)['type']);
    }

    #[Test]
    public function executeFiltersByType(): void
    {
        $this->writeFixture('14.0', 'Breaking-100001-RemoveFooBar.rst', 'Breaking: #100001 - Remove FooBar'."\n");
        $this->writeFixture('14.0', 'Feature-100002-AddFooBar.rst', 'Feature: #100002 - Add FooBar'."\n");

        $tester = new CommandTester($this->command());
        $tester->execute(['query' => 'foobar', '--type' => 'Feature']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame(1, $result['resultCount']);
        $results = $result['results'];
        self::assertIsArray($results);
        self::assertSame('Feature', $this->resultAt($results, 0)['type']);
    }

    #[Test]
    public function executeFailsForAnUnsupportedType(): void
    {
        $this->writeFixture('14.0', 'Breaking-100001-RemoveFooBar.rst', 'Breaking: #100001 - Remove FooBar'."\n");

        $tester = new CommandTester($this->command());
        $exitCode = $tester->execute(['query' => 'foobar', '--type' => 'Breakng']);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('type must be one of Breaking, Deprecation, Feature, or Important.', $result['error']);
    }

    #[Test]
    public function executeDefaultsTheVersionToTheInstalledMajor(): void
    {
        $this->writeFixture('13.4', 'Breaking-100001-RemoveFooBar.rst', 'Breaking: #100001 - Remove FooBar'."\n");
        $this->writeFixture('14.0', 'Breaking-100002-RemoveFooBar.rst', 'Breaking: #100002 - Remove FooBar'."\n");

        $tester = new CommandTester($this->command(majorVersion: 14));
        $tester->execute(['query' => 'foobar']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('14', $result['version']);
        self::assertSame(1, $result['resultCount']);
        $results = $result['results'];
        self::assertIsArray($results);
        self::assertSame('14.0', $this->resultAt($results, 0)['version']);
    }

    #[Test]
    public function executeWidensTheSearchWithAnExplicitVersionOverride(): void
    {
        $this->writeFixture('13.4', 'Breaking-100001-RemoveFooBar.rst', 'Breaking: #100001 - Remove FooBar'."\n");
        $this->writeFixture('14.0', 'Breaking-100002-RemoveFooBar.rst', 'Breaking: #100002 - Remove FooBar'."\n");

        $tester = new CommandTester($this->command(majorVersion: 14));
        $tester->execute(['query' => 'foobar', '--version' => '1']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame(2, $result['resultCount']);
    }

    #[Test]
    public function executeCapsAndFlagsTruncationAtTheLimit(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->writeFixture('14.0', sprintf('Breaking-10000%d-RemoveFooBar.rst', $i), sprintf('Breaking: #10000%d - Remove FooBar', $i)."\n");
        }

        $tester = new CommandTester($this->command());
        $tester->execute(['query' => 'foobar', '--limit' => '2']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame(3, $result['resultCount']);
        self::assertTrue($result['_truncated']);
        $results = $result['results'];
        self::assertIsArray($results);
        self::assertCount(2, $results);
    }

    #[Test]
    public function executeFailsForAnEmptyQuery(): void
    {
        mkdir($this->fixtureDir, 0o777, true);

        $tester = new CommandTester($this->command());
        $exitCode = $tester->execute(['query' => '   ']);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('query must not be empty.', $result['error']);
    }

    #[Test]
    public function executeFailsWithAnActionableErrorWhenTheChangelogDirectoryIsMissing(): void
    {
        $tester = new CommandTester(new ChangelogSearchCommand(new Typo3Version(), $this->fixtureDir.'/does-not-exist'));
        $exitCode = $tester->execute(['query' => 'anything']);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame(
            'typo3/cms-core does not ship a Documentation/Changelog directory in this installation.',
            $result['error'],
        );
    }

    /**
     * @param array<mixed> $results
     *
     * @return array<mixed>
     */
    private function resultAt(array $results, int $index): array
    {
        $entry = $results[$index];
        self::assertIsArray($entry);

        return $entry;
    }

    private function writeFixture(string $version, string $filename, string $content): void
    {
        $directory = $this->fixtureDir.'/'.$version;
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }
        file_put_contents($directory.'/'.$filename, $content);
    }

    private function command(int $majorVersion = 14): ChangelogSearchCommand
    {
        $typo3Version = $majorVersion === (new Typo3Version())->getMajorVersion()
            ? new Typo3Version()
            : new class($majorVersion) extends Typo3Version {
                public function __construct(private readonly int $major) {}

                public function getMajorVersion(): int
                {
                    return $this->major;
                }
            };

        return new ChangelogSearchCommand($typo3Version, $this->fixtureDir);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $absolute = $path.'/'.$entry;
            is_dir($absolute) ? $this->removeDirectory($absolute) : unlink($absolute);
        }
        rmdir($path);
    }
}
