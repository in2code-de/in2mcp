<?php

use In2code\In2mcp\Domain\Repository\BackendUserRepository;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

$llPrefix = 'LLL:EXT:in2mcp/Resources/Private/Language/locallang_db.xlf:';
$field = BackendUserRepository::API_KEY_FIELD;

$columns = [
    $field => [
        'label' => $llPrefix . 'be_users.' . $field,
        'description' => $llPrefix . 'be_users.' . $field . '.description',
        'config' => [
            'type' => 'password',
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

ExtensionManagementUtility::addTCAcolumns('be_users', $columns);
ExtensionManagementUtility::addToAllTCAtypes(
    'be_users',
    $field,
    '',
    'after:--div--;core.form.tabs:extended'
);
