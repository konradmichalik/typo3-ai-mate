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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Support;

use KonradMichalik\Typo3AiMate\Support\Redactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RedactorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class RedactorTest extends TestCase
{
    #[Test]
    public function redactsEmailAddresses(): void
    {
        $result = Redactor::redact('Login failed for john.doe@example.com from the form');

        self::assertStringNotContainsString('john.doe@example.com', $result);
        self::assertStringContainsString('[redacted-email]', $result);
    }

    #[Test]
    public function redactsIpv4Addresses(): void
    {
        $result = Redactor::redact('Request from 192.168.10.24 blocked');

        self::assertStringNotContainsString('192.168.10.24', $result);
        self::assertStringContainsString('[redacted-ip]', $result);
    }

    #[Test]
    public function redactsSecretKeyValuePairsKeepingTheKey(): void
    {
        self::assertSame('password=[redacted]', Redactor::redact('password=hunter2'));
        self::assertSame('api_key: [redacted]', Redactor::redact('api_key: abc123def'));
        self::assertStringContainsString('access_token=[redacted]', Redactor::redact('access_token=eyJhbGciOi&next=1'));
    }

    #[Test]
    public function leavesOrdinaryTextUntouched(): void
    {
        $text = 'Undefined array key "foo" in GeneralUtility.php line 42';

        self::assertSame($text, Redactor::redact($text));
    }
}
