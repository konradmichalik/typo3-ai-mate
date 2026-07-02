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

use KonradMichalik\Typo3AiMate\Mate\Typo3CliRunner;
use KonradMichalik\Typo3AiMate\Mcp\{DeprecationsTool, EventsTool, ExtensionScannerTool, FluidResolveTool, LogsTool, MiddlewaresTool, PageTool, RecordsTool, RenderPageTool, TcaTool, TsConfigTool, TypoScriptTool, UpgradeWizardsTool};
use KonradMichalik\Typo3AiMate\Mcp\Enum\{LogLevel, MiddlewareStack, OutputMode, TsConfigType, TypoScriptType};
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * McpToolWrappersTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class McpToolWrappersTest extends TestCase
{
    use DecodesResponses;

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

        self::assertSame('typo3-ai-mate:tca:dump', $result['command']);
        self::assertSame(['tt_content'], $result['args']);
    }

    #[Test]
    public function tcaToolForwardsTheListFlagWhenNoTableGiven(): void
    {
        $result = $this->decode((new TcaTool($this->runner))->dump());

        self::assertArrayHasKey('tables', $result);
        $forwarded = $result['tables'];
        self::assertIsArray($forwarded);
        self::assertSame('typo3-ai-mate:tca:dump', $forwarded['command']);
        self::assertSame(['--list'], $forwarded['args']);
    }

    #[Test]
    public function pageToolForwardsPageId(): void
    {
        $result = $this->decode((new PageTool($this->runner))->info(5));

        self::assertSame('typo3-ai-mate:page:info', $result['command']);
        self::assertSame(['5'], $result['args']);
    }

    #[Test]
    public function pageToolForwardsUrlOption(): void
    {
        $result = $this->decode((new PageTool($this->runner))->info(null, 'https://example.com/path'));

        self::assertSame('typo3-ai-mate:page:info', $result['command']);
        self::assertSame(['--url', 'https://example.com/path'], $result['args']);
    }

    #[Test]
    public function typoScriptToolForwardsPageIdTypeAndPath(): void
    {
        $result = $this->decode((new TypoScriptTool($this->runner))->dump(7, TypoScriptType::Constants, 'lib.foo'));

        self::assertSame('typo3-ai-mate:typoscript:dump', $result['command']);
        self::assertSame(['7', '--type', 'constants', '--path', 'lib.foo'], $result['args']);
    }

    #[Test]
    public function tsConfigToolDefaultsToPageTypeAndForwardsPageId(): void
    {
        $result = $this->decode((new TsConfigTool($this->runner))->dump(3));

        self::assertSame('typo3-ai-mate:tsconfig:dump', $result['command']);
        self::assertSame(['3', '--type', 'page'], $result['args']);
    }

    #[Test]
    public function tsConfigToolForwardsUserTypeUserUidAndPath(): void
    {
        $result = $this->decode((new TsConfigTool($this->runner))->dump(3, TsConfigType::User, 5, 'mod.web_layout'));

        self::assertSame('typo3-ai-mate:tsconfig:dump', $result['command']);
        self::assertSame(['3', '--type', 'user', '--user', '5', '--path', 'mod.web_layout'], $result['args']);
    }

    #[Test]
    public function fluidResolveToolForwardsPluginPathTemplateAndFormat(): void
    {
        $result = $this->decode((new FluidResolveTool($this->runner))->resolve(9, 'plugin.tx_news_pi1', 'News/List'));

        self::assertSame('typo3-ai-mate:fluid:resolve', $result['command']);
        self::assertSame(['9', '--plugin', 'plugin.tx_news_pi1', '--template', 'News/List', '--format', 'html'], $result['args']);
    }

    #[Test]
    public function fluidResolveToolForwardsPartialAndLayoutWithCustomFormat(): void
    {
        $result = $this->decode((new FluidResolveTool($this->runner))->resolve(9, 'page.10', null, 'Header', 'Default', 'xml'));

        self::assertSame('typo3-ai-mate:fluid:resolve', $result['command']);
        self::assertSame(['9', '--plugin', 'page.10', '--partial', 'Header', '--layout', 'Default', '--format', 'xml'], $result['args']);
    }

    #[Test]
    public function middlewaresToolForwardsTheStackOption(): void
    {
        $result = $this->decode((new MiddlewaresTool($this->runner))->list(MiddlewareStack::Backend));

        self::assertSame('typo3-ai-mate:middlewares:list', $result['command']);
        self::assertSame(['--stack', 'backend'], $result['args']);
    }

    #[Test]
    public function eventsToolForwardsTheEventFilter(): void
    {
        $result = $this->decode((new EventsTool($this->runner))->list('SomeEvent'));

        self::assertSame('typo3-ai-mate:events:list', $result['command']);
        self::assertSame(['--event', 'SomeEvent'], $result['args']);
    }

    #[Test]
    public function extensionScannerToolForwardsTheExtensionKeyAndDefaultsToSummary(): void
    {
        $result = $this->decode((new ExtensionScannerTool($this->runner))->scan('my_ext'));

        self::assertSame('typo3-ai-mate:upgrade:scan', $result['command']);
        self::assertSame(['my_ext', '--format', 'summary'], $result['args']);
    }

    #[Test]
    public function extensionScannerToolScansAllWhenNoExtensionGiven(): void
    {
        $result = $this->decode((new ExtensionScannerTool($this->runner))->scan());

        self::assertSame('typo3-ai-mate:upgrade:scan', $result['command']);
        self::assertSame(['--format', 'summary'], $result['args']);
    }

    #[Test]
    public function extensionScannerToolForwardsFullModeAndOwnCodeFlag(): void
    {
        $result = $this->decode((new ExtensionScannerTool($this->runner))->scan(null, OutputMode::Full, true));

        self::assertSame('typo3-ai-mate:upgrade:scan', $result['command']);
        self::assertSame(['--format', 'full', '--own-code'], $result['args']);
    }

    #[Test]
    public function upgradeWizardsToolCallsTheWizardsCommand(): void
    {
        $result = $this->decode((new UpgradeWizardsTool($this->runner))->list());

        self::assertSame('typo3-ai-mate:upgrade:wizards', $result['command']);
        self::assertSame([], $result['args']);
    }

    #[Test]
    public function renderPageToolForwardsThePageIdAndLanguage(): void
    {
        $result = $this->decode((new RenderPageTool($this->runner))->render(5));

        self::assertSame('typo3-ai-mate:fe:render', $result['command']);
        self::assertSame(['5', '--language', '0'], $result['args']);
    }

    #[Test]
    public function renderPageToolForwardsAnExplicitUrl(): void
    {
        $result = $this->decode((new RenderPageTool($this->runner))->render(null, 'https://example.com/page'));

        self::assertSame('typo3-ai-mate:fe:render', $result['command']);
        self::assertSame(['--url', 'https://example.com/page', '--language', '0'], $result['args']);
    }

    #[Test]
    public function deprecationsToolCallsTheDeprecationsCommand(): void
    {
        $result = $this->decode((new DeprecationsTool($this->runner))->list());

        self::assertSame('typo3-ai-mate:upgrade:deprecations', $result['command']);
        self::assertSame([], $result['args']);
    }

    #[Test]
    public function recordsToolForwardsTheTableAndDefaultsToSummary(): void
    {
        $result = $this->decode((new RecordsTool($this->runner))->query('tt_content'));

        self::assertSame('typo3-ai-mate:records:query', $result['command']);
        self::assertSame(['tt_content', '--limit', '25', '--format', 'summary'], $result['args']);
    }

    #[Test]
    public function recordsToolForwardsNonEmptyFiltersOnlyAndOmitsTheDefaultFlag(): void
    {
        $result = $this->decode((new RecordsTool($this->runner))->query('tt_content', null, 42, 'CType=text'));

        self::assertSame('typo3-ai-mate:records:query', $result['command']);
        self::assertSame(['tt_content', '--pid', '42', '--where', 'CType=text', '--limit', '25', '--format', 'summary'], $result['args']);
    }

    #[Test]
    public function recordsToolForwardsFullModeFieldsOrderAndEnableFieldsFlag(): void
    {
        $result = $this->decode((new RecordsTool($this->runner))->query('pages', 5, null, null, 'uid,title', 10, 'title:desc', OutputMode::Full, true));

        self::assertSame('typo3-ai-mate:records:query', $result['command']);
        self::assertSame(['pages', '--uid', '5', '--fields', 'uid,title', '--limit', '10', '--order-by', 'title:desc', '--format', 'full', '--respect-enable-fields'], $result['args']);
    }

    #[Test]
    public function logsSearchForwardsNonEmptyFiltersOnlyAndDefaultsToSummary(): void
    {
        $result = $this->decode((new LogsTool($this->runner))->search('boom', LogLevel::Error));

        self::assertSame('typo3-ai-mate:logs:search', $result['command']);
        self::assertSame(['--query', 'boom', '--level', 'error', '--limit', '50', '--format', 'summary'], $result['args']);
    }

    #[Test]
    public function logsByLevelForwardsLevelRequestIdAndMode(): void
    {
        $result = $this->decode((new LogsTool($this->runner))->byLevel(LogLevel::Error, 'abc123', 50, OutputMode::Full, '2h'));

        self::assertSame('typo3-ai-mate:logs:search', $result['command']);
        self::assertSame(['--level', 'error', '--request-id', 'abc123', '--limit', '50', '--format', 'full', '--since', '2h'], $result['args']);
    }

    #[Test]
    public function logsTailForwardsTheLimitAndDefaultsToSummary(): void
    {
        $result = $this->decode((new LogsTool($this->runner))->tail(10));

        self::assertSame('typo3-ai-mate:logs:search', $result['command']);
        self::assertSame(['--limit', '10', '--format', 'summary'], $result['args']);
    }
}
