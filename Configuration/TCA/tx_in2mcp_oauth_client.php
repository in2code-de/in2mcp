<?php

use In2code\In2mcp\Domain\Model\OAuthClient;
use In2code\In2mcp\Domain\Repository\BackendUserRepository;

defined('TYPO3') || die();

$llPrefix = 'LLL:EXT:in2mcp/Resources/Private/Language/locallang_db.xlf:tx_in2mcp_oauth_client';

return [
    'ctrl' => [
        'title' => $llPrefix,
        'label' => 'client_name',
        'label_alt' => 'client_id',
        'crdate' => 'crdate',
        'default_sortby' => 'client_name',
        'rootLevel' => 1,
        'adminOnly' => true,
        'typeicon_classes' => [
            'default' => 'actions-key',
        ],
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [
        'client_name' => [
            'label' => $llPrefix . '.client_name',
            'config' => [
                'type' => 'input',
                'required' => true,
                'max' => 100,
            ],
        ],
        'client_id' => [
            'label' => $llPrefix . '.client_id',
            'description' => $llPrefix . '.client_id.description',
            'config' => [
                'type' => 'input',
                'required' => true,
                'max' => 255,
                'eval' => 'trim,unique',
            ],
        ],
        'client_secret_hash' => [
            'label' => $llPrefix . '.client_secret_hash',
            'description' => $llPrefix . '.client_secret_hash.description',
            'displayCond' => 'FIELD:registration_type:=:' . OAuthClient::TYPE_MANUAL,
            'config' => [
                'type' => 'password',
                'passwordPolicy' => BackendUserRepository::API_KEY_PASSWORD_POLICY,
                'fieldControl' => [
                    'passwordGenerator' => [
                        'renderType' => 'passwordGenerator',
                        'options' => [
                            'allowEdit' => false,
                            'passwordRules' => [
                                'length' => 64,
                                'random' => 'hex',
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'redirect_uris' => [
            'label' => $llPrefix . '.redirect_uris',
            'description' => $llPrefix . '.redirect_uris.description',
            'config' => [
                'type' => 'text',
                'required' => true,
                'rows' => 3,
            ],
        ],
        'registration_type' => [
            'label' => $llPrefix . '.registration_type',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'readOnly' => true,
                'default' => OAuthClient::TYPE_MANUAL,
                'items' => [
                    ['label' => $llPrefix . '.registration_type.manual', 'value' => OAuthClient::TYPE_MANUAL],
                    ['label' => $llPrefix . '.registration_type.dcr', 'value' => OAuthClient::TYPE_DYNAMIC],
                    [
                        'label' => $llPrefix . '.registration_type.cimd',
                        'value' => OAuthClient::TYPE_METADATA_DOCUMENT,
                    ],
                ],
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'client_name, client_id, client_secret_hash, redirect_uris, registration_type',
        ],
    ],
];
