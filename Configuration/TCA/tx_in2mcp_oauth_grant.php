<?php

defined('TYPO3') || die();

$llPrefix = 'LLL:EXT:in2mcp/Resources/Private/Language/locallang_db.xlf:tx_in2mcp_oauth_grant';

// The tokens themselves have no column here: only their hashes are stored, and nobody has to see those
return [
    'ctrl' => [
        'title' => $llPrefix,
        'label' => 'client_name',
        'label_alt' => 'scope',
        'label_alt_force' => true,
        'crdate' => 'crdate',
        'tstamp' => 'tstamp',
        'default_sortby' => 'tstamp DESC',
        'rootLevel' => 1,
        'adminOnly' => true,
        'typeicon_classes' => [
            'default' => 'actions-link',
        ],
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'be_user' => [
            'label' => $llPrefix . '.be_user',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'be_users',
                'readOnly' => true,
            ],
        ],
        'client_name' => [
            'label' => $llPrefix . '.client_name',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'scope' => [
            'label' => $llPrefix . '.scope',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
            ],
        ],
        'crdate' => [
            'label' => $llPrefix . '.crdate',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'tstamp' => [
            'label' => $llPrefix . '.tstamp',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
        'refresh_token_expires' => [
            'label' => $llPrefix . '.refresh_token_expires',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'client_name, be_user, scope, crdate, tstamp, refresh_token_expires',
        ],
    ],
];
