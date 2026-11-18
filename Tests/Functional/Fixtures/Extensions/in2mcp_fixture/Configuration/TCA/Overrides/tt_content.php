<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

ExtensionManagementUtility::addTCAcolumns('tt_content', [
    'tx_in2mcpfixture_secret' => [
        'label' => 'Secret',
        'config' => [
            'type' => 'password',
            'hashed' => false,
        ],
    ],
]);
