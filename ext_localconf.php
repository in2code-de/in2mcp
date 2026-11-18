<?php

use In2code\In2mcp\Domain\Mcp\Authentication\ApiKeyAuthenticationService;
use In2code\In2mcp\Domain\Mcp\Authentication\BackendUserAuthenticator;
use In2code\In2mcp\Domain\Repository\BackendUserRepository;
use In2code\In2mcp\Domain\Service\UploadTokenService;
use TYPO3\CMS\Core\Cache\Backend\FileBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\PasswordPolicy\Validator\CorePasswordValidator;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

/**
 * Authentication service that authenticates a backend user by the api key of a MCP request
 */
ExtensionManagementUtility::addService(
    'in2mcp',
    'auth',
    ApiKeyAuthenticationService::class,
    [
        'title' => 'MCP api key authentication',
        'description' => 'Authenticates a backend user by the MCP api key of the request',
        'subtype' => 'getUserBE,authUserBE',
        'available' => true,
        'priority' => 60,
        'quality' => 60,
        'os' => '',
        'exec' => '',
        'className' => ApiKeyAuthenticationService::class,
    ]
);

/**
 * An api key typed into the backend form by hand has to be as long as a generated one is guessable - the generator
 * creates 128 characters. Character classes are not required, a long random key does not need them.
 */
$GLOBALS['TYPO3_CONF_VARS']['SYS']['passwordPolicies'][BackendUserRepository::API_KEY_PASSWORD_POLICY] ??= [
    'validators' => [
        CorePasswordValidator::class => [
            'options' => [
                'minimumLength' => BackendUserRepository::API_KEY_MINIMUM_LENGTH,
                'upperCaseCharacterRequired' => false,
                'lowerCaseCharacterRequired' => false,
                'digitCharacterRequired' => false,
                'specialCharacterRequired' => false,
            ],
            'excludeActions' => [],
        ],
    ],
];

/**
 * MCP is opened to editors per backend group ("Custom module options"), administrators always have it
 */
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions'][BackendUserAuthenticator::PERMISSION_OPTIONS] = [
    'header' => 'LLL:EXT:in2mcp/Resources/Private/Language/locallang_db.xlf:custom_options.header',
    'items' => [
        BackendUserAuthenticator::PERMISSION_ACCESS => [
            'LLL:EXT:in2mcp/Resources/Private/Language/locallang_db.xlf:custom_options.access',
            '',
            'LLL:EXT:in2mcp/Resources/Private/Language/locallang_db.xlf:custom_options.access.description',
        ],
    ],
];

/**
 * One-time tokens of the upload endpoint. A file backend needs no database table, and a flushed cache only
 * invalidates tokens that are open - they are requested again with "create_file_upload".
 */
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][UploadTokenService::CACHE_IDENTIFIER] ??= [
    'frontend' => VariableFrontend::class,
    'backend' => FileBackend::class,
    'options' => [
        'defaultLifetime' => UploadTokenService::LIFETIME,
    ],
];
