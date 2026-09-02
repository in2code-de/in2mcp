<?php

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
    ],
];
