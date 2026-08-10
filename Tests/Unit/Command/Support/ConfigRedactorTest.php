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

namespace KonradMichalik\Typo3AiMate\Tests\Unit\Command\Support;

use KonradMichalik\Typo3AiMate\Command\Support\ConfigRedactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ConfigRedactorTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class ConfigRedactorTest extends TestCase
{
    #[Test]
    public function encryptionKeyNeverLeaksRegardlessOfNestingDepth(): void
    {
        $secret = 'a-very-real-typo3-encryption-key';

        self::assertSame('[redacted]', $this->redactArray(['encryptionKey' => $secret])['encryptionKey']);

        $nested = $this->redactArray(['SYS' => ['encryptionKey' => $secret]]);
        $sys = $nested['SYS'];
        self::assertIsArray($sys);
        self::assertSame('[redacted]', $sys['encryptionKey']);

        $deeplyNested = $this->redactArray(['a' => ['b' => ['c' => ['encryptionKey' => $secret]]]]);
        $a = $deeplyNested['a'];
        self::assertIsArray($a);
        $b = $a['b'];
        self::assertIsArray($b);
        $c = $b['c'];
        self::assertIsArray($c);
        self::assertSame('[redacted]', $c['encryptionKey']);

        $encoded = json_encode($this->redactArray([
            'SYS' => ['encryptionKey' => $secret],
            'EXTENSIONS' => ['my_ext' => ['nested' => ['encryptionKey' => $secret]]],
        ]));
        self::assertIsString($encoded);
        self::assertStringNotContainsString($secret, $encoded);
    }

    #[Test]
    public function aDatabaseDsnWithAnEmbeddedPasswordIsRedactedAsASecondPass(): void
    {
        $config = [
            'DB' => [
                'Connections' => [
                    'Default' => [
                        // The key ("dsn") is not itself sensitive - the secret is embedded in the value.
                        'dsn' => 'mysql:host=localhost;dbname=typo3;user=root;password=hunter2',
                    ],
                ],
            ],
        ];

        $redacted = $this->redactArray($config);
        $db = $redacted['DB'];
        self::assertIsArray($db);
        $connections = $db['Connections'];
        self::assertIsArray($connections);
        $default = $connections['Default'];
        self::assertIsArray($default);
        $dsn = $default['dsn'];
        self::assertIsString($dsn);

        self::assertStringNotContainsString('hunter2', $dsn);
        self::assertStringContainsString('password=[redacted]', $dsn);
    }

    #[Test]
    public function maskWholeValueForKeysMatchingTheSensitivePattern(): void
    {
        $redacted = $this->redactArray([
            'password' => 'hunter2',
            'apiSecret' => 'xyz',
            'accessToken' => 'abc',
            'dbCredentials' => ['user' => 'root'],
            'privateKeyPath' => '/etc/keys/id_rsa',
        ]);

        self::assertSame('[redacted]', $redacted['password']);
        self::assertSame('[redacted]', $redacted['apiSecret']);
        self::assertSame('[redacted]', $redacted['accessToken']);
        self::assertSame('[redacted]', $redacted['dbCredentials']);
        self::assertSame('[redacted]', $redacted['privateKeyPath']);
    }

    #[Test]
    public function masksExactKeysNotCoveredByTheSubstringPattern(): void
    {
        $redacted = $this->redactArray(['key' => 'x', 'apiKey' => 'y', 'authCode' => 'z']);

        self::assertSame('[redacted]', $redacted['key']);
        self::assertSame('[redacted]', $redacted['apiKey']);
        self::assertSame('[redacted]', $redacted['authCode']);
    }

    #[Test]
    public function keyMatchingIsCaseInsensitive(): void
    {
        $redacted = $this->redactArray(['PASSWORD' => 'x', 'ApiKey' => 'y']);

        self::assertSame('[redacted]', $redacted['PASSWORD']);
        self::assertSame('[redacted]', $redacted['ApiKey']);
    }

    #[Test]
    public function leavesNonSensitiveValuesUntouched(): void
    {
        $config = ['host' => 'localhost', 'port' => 3306, 'debug' => true, 'timeout' => null];

        self::assertSame($config, $this->redactArray($config));
    }

    #[Test]
    public function recursesIntoNestedArraysThatAreNotThemselvesSensitive(): void
    {
        $redacted = $this->redactArray([
            'FE' => ['cacheHash' => ['enforceValidation' => true]],
        ]);

        self::assertSame(['FE' => ['cacheHash' => ['enforceValidation' => true]]], $redacted);
    }

    #[Test]
    public function passesScalarAndNullValuesThrough(): void
    {
        self::assertSame(42, ConfigRedactor::redact(42));
        self::assertTrue(ConfigRedactor::redact(true));
        self::assertNull(ConfigRedactor::redact(null));
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private function redactArray(array $value): array
    {
        /** @var array<string, mixed> $redacted */
        $redacted = ConfigRedactor::redact($value);

        return $redacted;
    }
}
