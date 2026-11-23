<?php

use In2code\In2mcp\Domain\Repository\BackendUserRepository;
use In2code\In2mcp\Form\AuthenticationFieldDisplayCondition;
use In2code\In2mcp\Form\NoOAuthGrantsInformation;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

$llPrefix = 'LLL:EXT:in2mcp/Resources/Private/Language/locallang_db.xlf:';
$field = BackendUserRepository::API_KEY_FIELD;

$columns = [
    $field => [
        'label' => $llPrefix . 'be_users.' . $field,
        'description' => $llPrefix . 'be_users.' . $field . '.description',
        'displayCond' => 'USER:' . AuthenticationFieldDisplayCondition::class . '->isApiKeyAuthenticationActivated',
        'config' => [
            'type' => 'password',
            'passwordPolicy' => BackendUserRepository::API_KEY_PASSWORD_POLICY,
            'fieldControl' => [
                'passwordGenerator' => [
                    'renderType' => 'passwordGenerator',
                    'options' => [
                        'title' => $llPrefix . 'be_users.' . $field . '.generate',
                        'allowEdit' => false,
                        'passwordRules' => [
                            'length' => 128,
                            'random' => 'base64',
                        ],
                    ],
                ],
            ],
        ],
    ],
];

// Revoking a connection is deleting its grant, the tokens stop working with the next request
$columns['in2mcp_oauth_grants'] = [
    'label' => $llPrefix . 'be_users.in2mcp_oauth_grants',
    'description' => $llPrefix . 'be_users.in2mcp_oauth_grants.description',
    'displayCond' => 'USER:' . AuthenticationFieldDisplayCondition::class . '->isOAuthActivated',
    'config' => [
        'type' => 'inline',
        'foreign_table' => 'tx_in2mcp_oauth_grant',
        'foreign_field' => 'be_user',
        'appearance' => [
            'collapseAll' => true,
            'enabledControls' => [
                'info' => true,
                'new' => false,
                'dragdrop' => false,
                'sort' => false,
                'hide' => false,
                'delete' => true,
                'localize' => false,
            ],
            'showNewRecordLink' => false,
            'showAllLocalizationLink' => false,
        ],
        'customControls' => [
            ['userFunc' => NoOAuthGrantsInformation::class . '->render'],
        ],
    ],
];

ExtensionManagementUtility::addTCAcolumns('be_users', $columns);
ExtensionManagementUtility::addToAllTCAtypes(
    'be_users',
    'in2mcp_oauth_grants, ' . $field,
    '',
    'after:--div--;core.form.tabs:extended'
);
