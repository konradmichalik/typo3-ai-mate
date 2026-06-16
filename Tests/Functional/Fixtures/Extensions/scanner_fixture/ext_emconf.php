<?php

declare(strict_types=1);

$EM_CONF['scanner_fixture'] = [
    'title' => 'Scanner Fixture',
    'description' => 'Fixture extension with removed core API usage, used by the extension scanner functional test.',
    'category' => 'misc',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
    ],
];
