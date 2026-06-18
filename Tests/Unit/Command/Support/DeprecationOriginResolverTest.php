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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command\Support;

use KonradMichalik\Typo3AiMate\Command\Support\DeprecationOriginResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * DeprecationOriginResolverTest.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 * @license GPL-2.0-or-later
 */
final class DeprecationOriginResolverTest extends TestCase
{
    #[Test]
    public function extractSymbolsPicksCamelCaseAndMethodTokensButDropsProse(): void
    {
        $resolver = new DeprecationOriginResolver([]);

        $symbols = $resolver->extractSymbols('GeneralUtility::getRequest() will be removed in TYPO3 v14; use the PSR-7 request instead.');
        self::assertContains('getRequest', $symbols);

        // Plain prose words must not be treated as identifiers.
        self::assertNotContains('removed', $symbols);
        self::assertNotContains('will', $symbols);
        // "typo3" is a stop word even though it survives the patterns.
        self::assertNotContains('typo3', $symbols);
    }

    #[Test]
    public function resolveFindsTheCallerStaticallyWhenNoTraceIsPresent(): void
    {
        $resolver = new DeprecationOriginResolver([
            [
                'path' => '/app/packages/my_ext/Classes/JsCheck.php',
                'label' => 'my_ext/Classes/JsCheck.php',
                'content' => "<?php\n\$x = 1;\n\$renderer->useNonce(true);\n",
            ],
        ]);

        $origins = $resolver->resolve('Argument $useNonce is deprecated and will be removed.');

        self::assertCount(1, $origins);
        self::assertSame('my_ext/Classes/JsCheck.php', $origins[0]['file']);
        self::assertSame(3, $origins[0]['line']);
        self::assertSame('useNonce', $origins[0]['symbol']);
        self::assertSame('static', $origins[0]['via']);
        self::assertSame('low', $origins[0]['confidence']);
    }

    #[Test]
    public function resolvePrefersAnOwnCodeTraceFrameOverStaticSearch(): void
    {
        $ownFiles = [
            [
                'path' => '/app/packages/my_ext/Classes/Caller.php',
                'label' => 'my_ext/Classes/Caller.php',
                'content' => "<?php\n\$renderer->getRequest();\n",
            ],
        ];
        $resolver = new DeprecationOriginResolver($ownFiles);

        $trace = implode("\n", [
            '#0 /var/www/vendor/typo3/cms-core/Classes/Page/AssetRenderer.php(153): doStuff()',
            '#1 /app/packages/my_ext/Classes/Caller.php(2): TYPO3\\CMS\\Core\\Page\\AssetRenderer->render()',
            '#2 {main}',
        ]);

        $origins = $resolver->resolve('getRequest() is deprecated.', $trace);

        self::assertCount(1, $origins);
        // The vendor frame (#0) is skipped; the first own-code frame (#1) wins.
        self::assertSame('my_ext/Classes/Caller.php', $origins[0]['file']);
        self::assertSame(2, $origins[0]['line']);
        self::assertSame('trace', $origins[0]['via']);
        self::assertSame('high', $origins[0]['confidence']);
    }

    #[Test]
    public function resolveReturnsEmptyWhenTheSymbolIsNowhereInOwnCode(): void
    {
        $resolver = new DeprecationOriginResolver([
            [
                'path' => '/app/packages/my_ext/Classes/Unrelated.php',
                'label' => 'my_ext/Classes/Unrelated.php',
                'content' => "<?php\necho 'nothing to see';\n",
            ],
        ]);

        self::assertSame([], $resolver->resolve('SomeOtherApi::legacyCall() is deprecated.'));
    }

    #[Test]
    public function resolveCapsTheNumberOfStaticOrigins(): void
    {
        $lines = ['<?php'];
        for ($i = 0; $i < 20; ++$i) {
            $lines[] = '$renderer->useNonce();';
        }
        $resolver = new DeprecationOriginResolver([
            [
                'path' => '/app/packages/my_ext/Classes/Many.php',
                'label' => 'my_ext/Classes/Many.php',
                'content' => implode("\n", $lines),
            ],
        ]);

        $origins = $resolver->resolve('useNonce is deprecated.');

        self::assertLessThanOrEqual(5, count($origins));
    }
}
