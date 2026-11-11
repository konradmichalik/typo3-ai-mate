<?php

declare(strict_types=1);

$EM_CONF['service_tag_fixture'] = [
    'title' => 'Service Tag Fixture',
    'description' => 'Fixture extension with tagged services, used by the service tag lookup functional test.',
    'category' => 'misc',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
    ],
];
