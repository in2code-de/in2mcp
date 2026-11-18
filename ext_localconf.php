<?php

use In2code\In2mcp\Domain\Mcp\Authentication\ApiKeyAuthenticationService;
use In2code\In2mcp\Domain\Repository\BackendUserRepository;
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
                'minimumLength' => 64,
                'upperCaseCharacterRequired' => false,
                'lowerCaseCharacterRequired' => false,
                'digitCharacterRequired' => false,
                'specialCharacterRequired' => false,
            ],
            'excludeActions' => [],
        ],
    ],
];
