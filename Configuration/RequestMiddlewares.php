<?php

use In2code\In2mcp\Middleware\McpAuthorization;
use In2code\In2mcp\Middleware\McpAuthorizationServerMetadata;
use In2code\In2mcp\Middleware\McpClientRegistration;
use In2code\In2mcp\Middleware\McpFileUpload;
use In2code\In2mcp\Middleware\McpProtectedResourceMetadata;
use In2code\In2mcp\Middleware\McpServer;

return [
    'backend' => [
        'in2mcp/mcpserver' => [
            'target' => McpServer::class,
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
            'before' => [
                'typo3/cms-backend/backend-routing',
            ],
        ],
        'in2mcp/fileupload' => [
            'target' => McpFileUpload::class,
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
            'before' => [
                'typo3/cms-backend/backend-routing',
            ],
        ],
        'in2mcp/protected-resource-metadata' => [
            'target' => McpProtectedResourceMetadata::class,
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
            'before' => [
                'typo3/cms-backend/backend-routing',
            ],
        ],
        'in2mcp/client-registration' => [
            'target' => McpClientRegistration::class,
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
            'before' => [
                'typo3/cms-backend/backend-routing',
            ],
        ],
        'in2mcp/authorization' => [
            'target' => McpAuthorization::class,
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
            'before' => [
                'typo3/cms-backend/backend-routing',
            ],
        ],
    ],
    'frontend' => [
        'in2mcp/authorization-server-metadata' => [
            'target' => McpAuthorizationServerMetadata::class,
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
            'before' => [
                'typo3/cms-frontend/site',
                'typo3/cms-frontend/eid',
            ],
        ],
    ],
];
