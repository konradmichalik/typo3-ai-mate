<?php

declare(strict_types=1);

$EM_CONF['overrides_fixture'] = [
    'title' => 'Overrides Fixture',
    'description' => 'Fixture extension with an XCLASS registration, used by the overrides functional test.',
    'category' => 'misc',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
    ],
];
