<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'in2mcp - MCP Server for TYPO3',
    'description' => 'Model Context Protocol (MCP) server for TYPO3 - connect Claude, Gemini and other MCP clients to your TYPO3 installation',
    'category' => 'plugin',
    'version' => '1.0.0',
    'author' => 'Alex Kellner',
    'author_email' => 'alexander.kellner@in2code.de',
    'author_company' => 'in2code.de',
    'state' => 'alpha',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.9.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
