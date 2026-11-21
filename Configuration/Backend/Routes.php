<?php

use In2code\In2mcp\Controller\AuthorizationController;

return [
    AuthorizationController::ROUTE => [
        'path' => '/in2mcp/authorize',
        // No backend token in the url, the login is still required - see AuthorizationController
        'access' => 'public',
        'target' => AuthorizationController::class . '::consentAction',
        'redirect' => [
            'enable' => true,
            'parameters' => [
                AuthorizationController::REQUEST_PARAMETER => true,
            ],
        ],
    ],
];
