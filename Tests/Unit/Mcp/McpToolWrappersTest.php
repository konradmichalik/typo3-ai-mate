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
use KonradMichalik\Typo3AiMate\Mate\Typo3CliRunner;
use KonradMichalik\Typo3AiMate\Mcp\{CommandsTool, DbSchemaTool, DeprecationsTool, EventsTool, ExtensionScannerTool, FluidResolveTool, LogsTool, MiddlewaresTool, PageTool, RecordsTool, RenderPageTool, TcaTool, TsConfigTool, TypoScriptTool, UpgradeWizardsTool};
use KonradMichalik\Typo3AiMate\Mcp\Enum\{LogLevel, MiddlewareStack, OutputMode, TsConfigType, TypoScriptType};
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * McpToolWrappersTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class McpToolWrappersTest extends TestCase
{
    use DecodesResponses;
    use JsonAssertions;

    private string $rootDir;
    private Typo3CliRunner $runner;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/typo3-ai-mate-mcp-'.bin2hex(random_bytes(8));
        mkdir($this->rootDir.'/vendor/bin', 0777, true);
        file_put_contents(
            $this->rootDir.'/vendor/bin/typo3',
            "<?php echo json_encode(['command' => \$argv[1] ?? null, 'args' => array_slice(\$argv, 2)]);",
        );
        $this->runner = new Typo3CliRunner($this->rootDir);
    }

    protected function tearDown(): void
    {
        @unlink($this->rootDir.'/vendor/bin/typo3');
        @rmdir($this->rootDir.'/vendor/bin');
        @rmdir($this->rootDir.'/vendor');
        @rmdir($this->rootDir);
    }

    #[Test]
    public function tcaToolForwardsTheTableArgument(): void
    {
        $result = $this->decode((new TcaTool($this->runner))->dump('tt_content'));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:tca:dump');
        self::assertJsonPath($result, 'args', ['--', 'tt_content']);
    }

    #[Test]
    public function tcaToolForwardsTheListFlagWhenNoTableGiven(): void
    {
        $result = $this->decode((new TcaTool($this->runner))->dump());

        self::assertJsonPath($result, 'tables.command', 'typo3-ai-mate:tca:dump');
        self::assertJsonPath($result, 'tables.args', ['--list']);
    }

    #[Test]
    public function pageToolForwardsPageId(): void
    {
        $result = $this->decode((new PageTool($this->runner))->info(5));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:page:info');
        self::assertJsonPath($result, 'args', ['--', '5']);
    }

    #[Test]
    public function pageToolForwardsUrlOption(): void
    {
        $result = $this->decode((new PageTool($this->runner))->info(null, 'https://example.com/path'));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:page:info');
        self::assertJsonPath($result, 'args', ['--url', 'https://example.com/path']);
    }

    #[Test]
    public function typoScriptToolForwardsPageIdTypeAndPath(): void
    {
        $result = $this->decode((new TypoScriptTool($this->runner))->dump(7, TypoScriptType::Constants, 'lib.foo'));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:typoscript:dump');
        self::assertJsonPath($result, 'args', ['--type', 'constants', '--path', 'lib.foo', '--', '7']);
    }

    #[Test]
    public function typoScriptToolForwardsTheFullFlag(): void
    {
        $result = $this->decode((new TypoScriptTool($this->runner))->dump(7, TypoScriptType::Setup, null, true));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:typoscript:dump');
        self::assertJsonPath($result, 'args', ['--type', 'setup', '--full', '--', '7']);
    }

    #[Test]
    public function tsConfigToolDefaultsToPageTypeAndForwardsPageId(): void
    {
        $result = $this->decode((new TsConfigTool($this->runner))->dump(3));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:tsconfig:dump');
        self::assertJsonPath($result, 'args', ['--type', 'page', '--', '3']);
    }

    #[Test]
    public function tsConfigToolForwardsUserTypeUserUidAndPath(): void
    {
        $result = $this->decode((new TsConfigTool($this->runner))->dump(3, TsConfigType::User, 5, 'mod.web_layout'));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:tsconfig:dump');
        self::assertJsonPath($result, 'args', ['--type', 'user', '--user', '5', '--path', 'mod.web_layout', '--', '3']);
    }

    #[Test]
    public function tsConfigToolForwardsTheFullFlag(): void
    {
        $result = $this->decode((new TsConfigTool($this->runner))->dump(3, TsConfigType::Page, null, null, true));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:tsconfig:dump');
        self::assertJsonPath($result, 'args', ['--type', 'page', '--full', '--', '3']);
    }

    #[Test]
    public function fluidResolveToolForwardsPluginPathTemplateAndFormat(): void
    {
        $result = $this->decode((new FluidResolveTool($this->runner))->resolve(9, 'plugin.tx_news_pi1', 'News/List'));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:fluid:resolve');
        self::assertJsonPath($result, 'args', ['--plugin', 'plugin.tx_news_pi1', '--template', 'News/List', '--format', 'html', '--', '9']);
    }

    #[Test]
    public function fluidResolveToolForwardsPartialAndLayoutWithCustomFormat(): void
    {
        $result = $this->decode((new FluidResolveTool($this->runner))->resolve(9, 'page.10', null, 'Header', 'Default', 'xml'));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:fluid:resolve');
        self::assertJsonPath($result, 'args', ['--plugin', 'page.10', '--partial', 'Header', '--layout', 'Default', '--format', 'xml', '--', '9']);
    }

    #[Test]
    public function middlewaresToolForwardsTheStackOption(): void
    {
        $result = $this->decode((new MiddlewaresTool($this->runner))->list(MiddlewareStack::Backend));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:middlewares:list');
        self::assertJsonPath($result, 'args', ['--stack', 'backend']);
    }

    #[Test]
    public function eventsToolForwardsTheEventFilter(): void
    {
        $result = $this->decode((new EventsTool($this->runner))->list('SomeEvent'));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:events:list');
        self::assertJsonPath($result, 'args', ['--event', 'SomeEvent']);
    }

    #[Test]
    public function commandsToolForwardsThePatternFilter(): void
    {
        $result = $this->decode((new CommandsTool($this->runner))->list('cache'));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:commands:list');
        self::assertJsonPath($result, 'args', ['--pattern', 'cache']);
    }

    #[Test]
    public function commandsToolForwardsTheOwnOnlyFlag(): void
    {
        $result = $this->decode((new CommandsTool($this->runner))->list(null, true));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:commands:list');
        self::assertJsonPath($result, 'args', ['--own-only']);
    }

    #[Test]
    public function commandsToolCallsTheCommandsCommandWithoutOptionsByDefault(): void
    {
        $result = $this->decode((new CommandsTool($this->runner))->list());

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:commands:list');
        self::assertJsonPath($result, 'args', []);
    }

    #[Test]
    public function dbSchemaToolForwardsTheTableArgument(): void
    {
        $result = $this->decode((new DbSchemaTool($this->runner))->dump('tt_content'));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:db-schema:dump');
        self::assertJsonPath($result, 'args', ['--', 'tt_content']);
    }

    #[Test]
    public function dbSchemaToolForwardsThePatternFilterWhenNoTableGiven(): void
    {
        $result = $this->decode((new DbSchemaTool($this->runner))->dump(null, 'tt_'));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:db-schema:dump');
        self::assertJsonPath($result, 'args', ['--pattern', 'tt_']);
    }

    #[Test]
    public function dbSchemaToolSuppressesThePatternFilterWhenTableIsGiven(): void
    {
        $result = $this->decode((new DbSchemaTool($this->runner))->dump('tt_content', 'tt_'));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:db-schema:dump');
        self::assertJsonPath($result, 'args', ['--', 'tt_content']);
    }

    #[Test]
    public function dbSchemaToolListsAllTablesWithoutOptionsByDefault(): void
    {
        $result = $this->decode((new DbSchemaTool($this->runner))->dump());

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:db-schema:dump');
        self::assertJsonPath($result, 'args', []);
    }

    #[Test]
    public function extensionScannerToolForwardsTheExtensionKeyAndDefaultsToSummary(): void
    {
        $result = $this->decode((new ExtensionScannerTool($this->runner))->scan('my_ext'));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:upgrade:scan');
        self::assertJsonPath($result, 'args', ['--format', 'summary', '--', 'my_ext']);
    }

    #[Test]
    public function extensionScannerToolScansAllWhenNoExtensionGiven(): void
    {
        $result = $this->decode((new ExtensionScannerTool($this->runner))->scan());

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:upgrade:scan');
        self::assertJsonPath($result, 'args', ['--format', 'summary']);
    }

    #[Test]
    public function extensionScannerToolForwardsFullModeAndOwnCodeFlag(): void
    {
        $result = $this->decode((new ExtensionScannerTool($this->runner))->scan(null, OutputMode::Full, true));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:upgrade:scan');
        self::assertJsonPath($result, 'args', ['--format', 'full', '--own-code']);
    }

    #[Test]
    public function upgradeWizardsToolCallsTheWizardsCommand(): void
    {
        $result = $this->decode((new UpgradeWizardsTool($this->runner))->list());

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:upgrade:wizards');
        self::assertJsonPath($result, 'args', []);
    }

    #[Test]
    public function renderPageToolForwardsThePageIdAndLanguage(): void
    {
        $result = $this->decode((new RenderPageTool($this->runner))->render(5));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:fe:render');
        self::assertJsonPath($result, 'args', ['--language', '0', '--', '5']);
    }

    #[Test]
    public function renderPageToolForwardsAnExplicitUrl(): void
    {
        $result = $this->decode((new RenderPageTool($this->runner))->render(null, 'https://example.com/page'));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:fe:render');
        self::assertJsonPath($result, 'args', ['--url', 'https://example.com/page', '--language', '0']);
    }

    #[Test]
    public function deprecationsToolCallsTheDeprecationsCommand(): void
    {
        $result = $this->decode((new DeprecationsTool($this->runner))->list());

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:upgrade:deprecations');
        self::assertJsonPath($result, 'args', []);
    }

    #[Test]
    public function recordsToolForwardsTheTableAndDefaultsToSummary(): void
    {
        $result = $this->decode((new RecordsTool($this->runner))->query('tt_content'));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:records:query');
        self::assertJsonPath($result, 'args', ['--limit', '25', '--format', 'summary', '--', 'tt_content']);
    }

    #[Test]
    public function recordsToolForwardsNonEmptyFiltersOnlyAndOmitsTheDefaultFlag(): void
    {
        $result = $this->decode((new RecordsTool($this->runner))->query('tt_content', null, 42, 'CType=text'));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:records:query');
        self::assertJsonPath($result, 'args', ['--pid', '42', '--where', 'CType=text', '--limit', '25', '--format', 'summary', '--', 'tt_content']);
    }

    #[Test]
    public function recordsToolForwardsFullModeFieldsOrderAndEnableFieldsFlag(): void
    {
        $result = $this->decode((new RecordsTool($this->runner))->query('pages', 5, null, null, 'uid,title', 10, 'title:desc', OutputMode::Full, true));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:records:query');
        self::assertJsonPath($result, 'args', ['--uid', '5', '--fields', 'uid,title', '--limit', '10', '--order-by', 'title:desc', '--format', 'full', '--respect-enable-fields', '--', 'pages']);
    }

    #[Test]
    public function logsSearchForwardsNonEmptyFiltersOnlyAndDefaultsToSummary(): void
    {
        $result = $this->decode((new LogsTool($this->runner))->search('boom', LogLevel::Error));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:logs:search');
        self::assertJsonPath($result, 'args', ['--query', 'boom', '--level', 'error', '--limit', '50', '--format', 'summary']);
    }

    #[Test]
    public function logsByLevelForwardsLevelRequestIdAndMode(): void
    {
        $result = $this->decode((new LogsTool($this->runner))->byLevel(LogLevel::Error, 'abc123', 50, OutputMode::Full, '2h'));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:logs:search');
        self::assertJsonPath($result, 'args', ['--level', 'error', '--request-id', 'abc123', '--limit', '50', '--format', 'full', '--since', '2h']);
    }

    #[Test]
    public function logsTailForwardsTheLimitAndDefaultsToSummary(): void
    {
        $result = $this->decode((new LogsTool($this->runner))->tail(10));

        self::assertJsonPath($result, 'command', 'typo3-ai-mate:logs:search');
        self::assertJsonPath($result, 'args', ['--limit', '10', '--format', 'summary']);
    }
}
