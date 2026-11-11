<?php

declare(strict_types=1);

$EM_CONF['compat_fixture'] = [
    'title' => 'Compatibility Fixture',
    'description' => 'Fixture extension that only allows TYPO3 v12, used by the upgrade compatibility functional test.',
    'category' => 'misc',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
        ],
    ],
];
