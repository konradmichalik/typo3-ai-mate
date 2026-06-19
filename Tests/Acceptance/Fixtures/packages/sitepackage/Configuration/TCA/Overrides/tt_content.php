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

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || exit('Access denied.');

// Register the two demo content element types. The associative item form is the
// v12+ API and is not deprecated on v13/v14.
$demoTypes = [
    'sitepackage_nplusone' => 'N+1 Demo (USER_INT)',
    'sitepackage_throws' => 'Exception Demo (USER_INT)',
];

foreach ($demoTypes as $cType => $label) {
    ExtensionManagementUtility::addTcaSelectItem(
        'tt_content',
        'CType',
        ['label' => $label, 'value' => $cType, 'group' => 'plugins'],
    );

    $GLOBALS['TCA']['tt_content']['types'][$cType] = [
        'showitem' => '--palette--;;general, header',
    ];
}
