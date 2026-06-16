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

namespace Test\Sitepackage\ContentObject;

use TYPO3\CMS\Core\Attribute\AsAllowedCallable;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use function sprintf;

/**
 * NplusOneDemoRenderer.
 *
 * @author Konrad Michalik <km@move-elevator.de>
 */
final class NplusOneDemoRenderer
{
    private const LOOKUPS = 25;

    /**
     * @param array<string, mixed> $conf
     */
    #[AsAllowedCallable]
    public function render(string $content, array $conf): string
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages');

        $titles = [];
        for ($uid = 1; $uid <= self::LOOKUPS; ++$uid) {
            // N+1: one round-trip per uid instead of a single IN () query.
            $title = $connection
                ->executeQuery('SELECT title FROM pages WHERE uid = '.$uid.' AND deleted = 0')
                ->fetchOne();

            if (false !== $title) {
                $titles[] = '<li>'.htmlspecialchars((string) $title).'</li>';
            }
        }

        return sprintf(
            '<p>Ran %d single-row page lookups (N+1).</p><ul class="nplusone-demo">%s</ul>',
            self::LOOKUPS,
            implode('', $titles),
        );
    }
}
