<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Fluid Rename',
    'description' => 'Helper to rename Fluid templates in a TYPO3 extension to *.fluid.* file extension',
    'category' => 'misc',
    'author' => 'Simon Praetorius',
    'author_email' => 'simon@praetorius.me',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-14.3.99',
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'Praetorius\\FluidRename\\' => 'Classes/',
        ],
    ],
];