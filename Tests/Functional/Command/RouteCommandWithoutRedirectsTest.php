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
 * RouteCommandWithoutRedirectsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RouteCommandWithoutRedirectsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'install',
    ];

    protected array $testExtensionsToLoad = [
        'typo3_ai_mate',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__.'/../Fixtures/pages_route.csv');
        RouteCommandTest::writeSiteConfiguration();
    }

    #[Test]
    public function resolvesThePageAndStatesThatRedirectsWereNotChecked(): void
    {
        $tester = new CommandTester($this->get(CommandRegistry::class)->get('typo3-ai-mate:route:resolve'));
        $exitCode = $tester->execute(['url' => 'https://example.test/products']);

        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);
        self::assertSame(0, $exitCode);
        self::assertSame('page', $result['outcome']);
        self::assertFalse($result['redirectsAvailable']);
        self::assertSame(2, $result['page']['id']);
    }
}
