<?php

declare(strict_types=1);

$EM_CONF['labels_fixture'] = [
    'title' => 'Labels Fixture',
    'description' => 'Fixture extension with label files and a German translation, used by the label lookup functional test.',
    'category' => 'misc',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
    ],
];
