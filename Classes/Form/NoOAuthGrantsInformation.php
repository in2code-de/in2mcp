<?php

declare(strict_types=1);

namespace In2code\In2mcp\Form;

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * An inline field reads no "fieldInformation" of its column, so the hint is rendered as custom control
 */
class NoOAuthGrantsInformation
{
    private const LABEL = 'LLL:EXT:in2mcp/Resources/Private/Language/locallang_db.xlf:be_users.in2mcp_oauth_grants.none';

    /**
     * @param array<string, mixed> $parameters
     */
    public function render(array $parameters): string
    {
        $html = '';
        $field = (string)($parameters['field'] ?? '');
        $grantUids = (string)($parameters['row'][$field] ?? '');
        if ($grantUids === '' || $grantUids === '0') {
            $html = '<p class="text-body-secondary">'
                . htmlspecialchars($this->getLanguageService()->sL(self::LABEL))
                . '</p>';
        }
        return $html;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
