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

use KonradMichalik\Typo3AiMate\Command\Support\SysLogFormatter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SysLogFormatterTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 * @license GPL-2.0-or-later
 */
final class SysLogFormatterTest extends TestCase
{
    private SysLogFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new SysLogFormatter();
    }

    #[Test]
    public function detailsFillsNamedPlaceholdersFromJsonLogData(): void
    {
        $details = $this->formatter->details([
            'type' => 1,
            'tablename' => 'tt_content',
            'details' => 'Attempt to modify record {table}:{uid} without permission or non-existing page',
            'log_data' => '{"table":"tt_content","uid":12}',
        ]);

        self::assertSame('Attempt to modify record tt_content:12 without permission or non-existing page', $details);
    }

    #[Test]
    public function detailsFillsPercentPlaceholdersFromLegacySerializedLogData(): void
    {
        $details = $this->formatter->details([
            'type' => 2,
            'tablename' => '',
            'details' => 'File "%s" was not uploaded',
            'log_data' => serialize(['report.pdf']),
        ]);

        self::assertSame('File "report.pdf" was not uploaded', $details);
    }

    #[Test]
    public function detailsRedactsIpAddressesAndEmailsFromFilledValues(): void
    {
        $details = $this->formatter->details([
            'type' => 1,
            'tablename' => 'tt_content',
            'details' => 'Record "{title}" was updated',
            'log_data' => '{"title":"Call 198.51.100.24 or write to jane.roe@example.org"}',
        ]);

        self::assertStringNotContainsString('198.51.100.24', $details);
        self::assertStringNotContainsString('jane.roe@example.org', $details);
        self::assertStringContainsString('[redacted-ip]', $details);
    }

    #[Test]
    public function detailsKeepsTheTemplateForLoginEntriesSoNoUserIdentityIsFilledIn(): void
    {
        $details = $this->formatter->details([
            'type' => 255,
            'tablename' => '',
            'details' => 'User %s logged in from ###IP###',
            'log_data' => '["jane.roe"]',
        ]);

        self::assertSame('User %s logged in from ###IP###', $details);
    }

    #[Test]
    public function detailsKeepsTheTemplateForUserTableRecords(): void
    {
        $details = $this->formatter->details([
            'type' => 1,
            'tablename' => 'be_users',
            'details' => 'Record "{title}" ({table}:{uid}) was updated',
            'log_data' => '{"title":"jane.roe","table":"be_users","uid":7}',
        ]);

        self::assertSame('Record "{title}" ({table}:{uid}) was updated', $details);
    }

    #[Test]
    public function aggregateGroupsIdenticalEventsCountsThemAndSortsNewestFirst(): void
    {
        $denied = ['tstamp' => 1000, 'type' => 1, 'action' => 2, 'error' => 1, 'tablename' => 'tt_content', 'recuid' => 12, 'event_pid' => 3, 'details' => 'Attempt to modify record {table}:{uid} denied', 'log_data' => '{"table":"tt_content","uid":12}', 'level' => 'info', 'component' => '', 'request_id' => 'aaa'];
        $inserted = ['tstamp' => 1500, 'type' => 1, 'action' => 1, 'error' => 0, 'tablename' => 'pages', 'recuid' => 5, 'event_pid' => 1, 'details' => 'Record was inserted', 'log_data' => ''];

        $entries = $this->formatter->aggregate([
            ['tstamp' => 2000, 'request_id' => 'bbb'] + $denied,
            $inserted,
            $denied,
        ]);

        self::assertCount(2, $entries);
        self::assertSame([
            'lastSeen' => date(\DATE_ATOM, 2000),
            'count' => 2,
            'type' => 'db',
            'action' => 'update',
            'error' => 'userError',
            'level' => 'info',
            'component' => '',
            'table' => 'tt_content',
            'recordUid' => 12,
            'pageUid' => 3,
            'exampleRequestId' => 'bbb',
            'details' => 'Attempt to modify record tt_content:12 denied',
        ], $entries[0]);
        self::assertSame('insert', $entries[1]['action']);
        self::assertSame('message', $entries[1]['error']);
    }

    #[Test]
    public function aggregateNeverCarriesUserIpOrWorkspaceColumns(): void
    {
        $entries = $this->formatter->aggregate([[
            'tstamp' => 1000, 'type' => 1, 'action' => 2, 'error' => 1, 'tablename' => 'tt_content', 'recuid' => 12,
            'event_pid' => 3, 'details' => 'Denied', 'log_data' => '', 'userid' => 4711, 'IP' => '203.0.113.7', 'workspace' => 1,
        ]]);

        $encoded = (string) json_encode($entries);
        self::assertStringNotContainsString('4711', $encoded);
        self::assertStringNotContainsString('203.0.113.7', $encoded);
        self::assertArrayNotHasKey('userid', $entries[0]);
        self::assertArrayNotHasKey('workspace', $entries[0]);
    }

    #[Test]
    public function aggregateReportsTheRawActionNumberOfTypesWithoutNamedActions(): void
    {
        $entries = $this->formatter->aggregate([[
            'tstamp' => 1000, 'type' => 4, 'action' => 0, 'error' => 2, 'tablename' => '', 'recuid' => 0,
            'event_pid' => -1, 'details' => 'Extension failed', 'log_data' => '',
        ]]);

        self::assertSame('extension', $entries[0]['type']);
        self::assertSame(0, $entries[0]['action']);
        self::assertSame('systemError', $entries[0]['error']);
    }

    #[Test]
    public function detailsFallsBackToThePsr3MessageWithoutInterpolatingItsData(): void
    {
        $details = $this->formatter->details([
            'type' => 0,
            'tablename' => '',
            'details' => null,
            'message' => 'Provider {provider} rejected the call from 192.0.2.44',
            'data' => '{"provider":"acme-pay"}',
        ]);

        self::assertSame('Provider {provider} rejected the call from [redacted-ip]', $details);
    }

    #[Test]
    public function aggregateCarriesLevelComponentAndRequestIdOfPsr3Rows(): void
    {
        $entries = $this->formatter->aggregate([[
            'tstamp' => 1000, 'type' => 0, 'action' => 0, 'error' => 0, 'tablename' => '', 'recuid' => 0, 'event_pid' => -1,
            'details' => '', 'log_data' => '', 'level' => 'error', 'component' => 'Vendor.Shop.Checkout', 'request_id' => '65f1a2b3c4d5e',
            'message' => 'Checkout failed', 'data' => '{"secretKey":"acme-pay"}',
        ]]);

        self::assertSame('error', $entries[0]['level']);
        self::assertSame('Vendor.Shop.Checkout', $entries[0]['component']);
        self::assertSame('65f1a2b3c4d5e', $entries[0]['exampleRequestId']);
        self::assertSame('Checkout failed', $entries[0]['details']);
        self::assertStringNotContainsString('acme-pay', (string) json_encode($entries));
    }

    #[Test]
    public function typeIdResolvesNamesAndRejectsUnknownOnes(): void
    {
        self::assertSame(1, $this->formatter->typeId('db'));
        self::assertSame(2, $this->formatter->typeId('FILE'));
        self::assertNull($this->formatter->typeId('nope'));
    }
}
