<?php

use In2code\In2mcp\Domain\Mcp\Authentication\ApiKeyAuthenticationService;
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
