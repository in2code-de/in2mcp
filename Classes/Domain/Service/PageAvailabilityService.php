<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Works on a record cleaned by TcaService, so a field the user may not read never becomes a reason
 */
class PageAvailabilityService
{
    private const FIELD_HIDDEN = 'hidden';
    private const FIELD_STARTTIME = 'starttime';
    private const FIELD_ENDTIME = 'endtime';
    private const FIELD_FRONTEND_GROUPS = 'fe_group';
    private const FIELD_DOKTYPE = 'doktype';
    private const DATE_FORMAT = 'Y-m-d H:i';

    // No group and "hide at login" leave a page visible for an anonymous visitor
    private const GROUPS_VISIBLE_WITHOUT_LOGIN = ['0', '-1'];

    // Spacer, folder and recycler are never rendered in the frontend
    private const DOKTYPES_WITHOUT_OUTPUT = [199, 254, 255];

    public function __construct(
        private readonly TcaService $tcaService,
        private readonly Context $context,
    ) {
    }

    /**
     * @param array<string, mixed> $page
     * @return list<string>
     */
    public function getReasonsForMissingOutput(array $page, SiteLanguage $language): array
    {
        $reasons = [];
        if ($language->isEnabled() === false) {
            $reasons[] = 'The language "' . $language->getTitle() . '" is disabled in the site configuration, only'
                . ' logged-in backend users see it';
        }
        if ((int)($page[self::FIELD_HIDDEN] ?? 0) === 1) {
            $reasons[] = 'The page is hidden';
        }
        $reasons = array_merge($reasons, $this->getReasonsOfTime($page), $this->getReasonsOfAccess($page));
        return $reasons;
    }

    /**
     * @param array<string, mixed> $page
     * @return list<string>
     */
    private function getReasonsOfTime(array $page): array
    {
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $startTime = (int)($page[self::FIELD_STARTTIME] ?? 0);
        $endTime = (int)($page[self::FIELD_ENDTIME] ?? 0);
        $reasons = [];
        if ($startTime > $now) {
            $reasons[] = 'The page is published from ' . date(self::DATE_FORMAT, $startTime) . ' on';
        }
        if ($endTime > 0 && $endTime <= $now) {
            $reasons[] = 'The publication of the page ended at ' . date(self::DATE_FORMAT, $endTime);
        }
        return $reasons;
    }

    /**
     * @param array<string, mixed> $page
     * @return list<string>
     */
    private function getReasonsOfAccess(array $page): array
    {
        $frontendGroups = array_diff(
            GeneralUtility::trimExplode(',', (string)($page[self::FIELD_FRONTEND_GROUPS] ?? ''), true),
            self::GROUPS_VISIBLE_WITHOUT_LOGIN
        );
        $doktype = (int)($page[self::FIELD_DOKTYPE] ?? 0);
        $reasons = [];
        if ($frontendGroups !== []) {
            $reasons[] = 'The page is restricted to frontend user groups (' . implode(',', $frontendGroups) . '),'
                . ' a visitor who is not logged in gets no page';
        }
        if (in_array($doktype, self::DOKTYPES_WITHOUT_OUTPUT, true)) {
            $reasons[] = 'Pages of type "' . $this->tcaService->getPageTypeName($doktype) . '" are not rendered';
        }
        return $reasons;
    }
}
