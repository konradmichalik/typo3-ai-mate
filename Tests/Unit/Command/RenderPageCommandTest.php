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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command;

use KonradMichalik\Typo3AiMate\Command\RenderPageCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\{ResponseInterface, StreamInterface, UriInterface};
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * RenderPageCommandTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
final class RenderPageCommandTest extends TestCase
{
    use WithTemporaryVarPath;

    protected function setUp(): void
    {
        $this->initVarPath();
    }

    protected function tearDown(): void
    {
        $this->cleanupVarPath();
    }

    #[Test]
    public function newEntriesSinceKeepsOnlyEntriesAtOrAfterTheBoundary(): void
    {
        $command = new RenderPageCommand(self::createStub(SiteFinder::class), self::createStub(RequestFactory::class));
        $boundary = strtotime('Mon, 15 Jun 2026 16:00:00 +0200');

        $entries = $command->newEntriesSince([
            ['time' => 'Mon, 15 Jun 2026 15:59:59 +0200', 'message' => 'before'],
            ['time' => 'Mon, 15 Jun 2026 16:00:01 +0200', 'message' => 'after'],
        ], $boundary);

        self::assertCount(1, $entries);
        self::assertSame('after', $entries[0]['message']);
    }

    #[Test]
    public function executeRendersTheResolvedUrlAndReportsStatusAndNewLogs(): void
    {
        $this->writeLog('test', [
            'Sun, 17 Jun 2035 10:00:00 +0200 [WARNING] request="r1" component="TYPO3.CMS.deprecations": Something is deprecated',
            'Mon, 15 Jun 2020 10:00:00 +0200 [INFO] request="r0" component="TYPO3.CMS.Core": Ancient unrelated entry',
        ]);

        $tester = new CommandTester(new RenderPageCommand(
            $this->siteFinderReturning('https://example.test/the-page'),
            $this->requestFactoryReturning(200, 4321),
        ));
        $exitCode = $tester->execute(['pageId' => 5]);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('https://example.test/the-page', $result['url']);
        self::assertSame(5, $result['pageId']);
        self::assertSame(200, $result['status']);
        self::assertSame(4321, $result['bytes']);

        $logs = $result['logs'];
        self::assertIsArray($logs);
        // The far-future entry is captured (and deduplicated); the 2020 entry is excluded by the boundary.
        self::assertSame(1, $logs['totalMatched']);
        self::assertSame(1, $logs['distinct']);
        $entries = $logs['entries'];
        self::assertIsArray($entries);
        $first = $entries[0];
        self::assertIsArray($first);
        self::assertSame('Something is deprecated', $first['message']);
        self::assertSame(1, $first['count']);
    }

    #[Test]
    public function executeResolvesARelativeUrlAgainstTheSiteBase(): void
    {
        $tester = new CommandTester(new RenderPageCommand(
            $this->siteFinderWithBase('https://example.test/'),
            $this->requestFactoryReturning(200, 10),
        ));
        $tester->execute(['--url' => '/some/path']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame('https://example.test/some/path', $result['url']);
        self::assertSame(200, $result['status']);
    }

    #[Test]
    public function executeFailsWhenNeitherPageIdNorUrlIsGiven(): void
    {
        $tester = new CommandTester(new RenderPageCommand(
            self::createStub(SiteFinder::class),
            self::createStub(RequestFactory::class),
        ));
        $exitCode = $tester->execute([]);

        self::assertSame(1, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertArrayHasKey('error', $result);
    }

    #[Test]
    public function executeReportsARequestErrorWithoutAborting(): void
    {
        $requestFactory = self::createStub(RequestFactory::class);
        $requestFactory->method('request')->willThrowException(new RuntimeException('Connection refused'));

        $tester = new CommandTester(new RenderPageCommand(self::createStub(SiteFinder::class), $requestFactory));
        $exitCode = $tester->execute(['--url' => 'https://example.test/']);

        self::assertSame(0, $exitCode);
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame(0, $result['status']);
        self::assertSame('Connection refused', $result['requestError']);
    }

    private function siteFinderReturning(string $url): SiteFinder
    {
        $uri = self::createStub(UriInterface::class);
        $uri->method('__toString')->willReturn($url);
        $router = self::createStub(RouterInterface::class);
        $router->method('generateUri')->willReturn($uri);
        $site = self::createStub(Site::class);
        $site->method('getRouter')->willReturn($router);
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getSiteByPageId')->willReturn($site);

        return $siteFinder;
    }

    private function siteFinderWithBase(string $base): SiteFinder
    {
        $uri = self::createStub(UriInterface::class);
        $uri->method('__toString')->willReturn($base);
        $site = self::createStub(Site::class);
        $site->method('getBase')->willReturn($uri);
        $siteFinder = self::createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['example' => $site]);

        return $siteFinder;
    }

    private function requestFactoryReturning(int $status, int $bytes): RequestFactory
    {
        $body = self::createStub(StreamInterface::class);
        $body->method('getSize')->willReturn($bytes);
        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($body);
        $requestFactory = self::createStub(RequestFactory::class);
        $requestFactory->method('request')->willReturn($response);

        return $requestFactory;
    }
}
