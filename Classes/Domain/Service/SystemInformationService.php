<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Exception\UserNotFoundException;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Which TYPO3 a client is connected to, so it can tell a production system from a development one before it
 * changes anything
 */
readonly class SystemInformationService
{
    public function __construct(
        private SiteFinder $siteFinder,
        private BackendUserService $backendUserService,
        private EndpointUrlService $endpointUrlService,
    ) {
    }

    /**
     * @return array<string, mixed>
     * @throws UserNotFoundException
     */
    public function getSystemInformation(): array
    {
        $context = Environment::getContext();
        return [
            'mcpServerUrl' => $this->endpointUrlService->getCurrentMcpServerUrl(),
            'applicationContext' => (string)$context,
            'isProduction' => $context->isProduction(),
            'sites' => array_map(
                fn (Site $site): array => $this->getSiteInformation($site),
                $this->findSitesOfBackendUser()
            ),
        ];
    }

    /**
     * Every site for a user with the whole tree, otherwise the sites the page mounts lie in
     *
     * @return list<Site>
     * @throws UserNotFoundException
     */
    private function findSitesOfBackendUser(): array
    {
        $sites = $this->siteFinder->getAllSites();
        if ($this->backendUserService->hasFullTreeAccess() === false) {
            $sites = [];
            foreach ($this->backendUserService->getWebMounts() as $pageUid) {
                $site = $this->findSiteOfPage($pageUid);
                if ($site !== null) {
                    $sites[$site->getIdentifier()] = $site;
                }
            }
        }
        return array_values($sites);
    }

    private function findSiteOfPage(int $pageUid): ?Site
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageUid);
        } catch (SiteNotFoundException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     * @throws UserNotFoundException
     */
    private function getSiteInformation(Site $site): array
    {
        $languages = $site->getAvailableLanguages($this->backendUserService->getBackendUser());
        return [
            'identifier' => $site->getIdentifier(),
            'rootPageUid' => $site->getRootPageId(),
            'base' => (string)$site->getBase(),
            'languages' => array_values(array_map(
                static fn (SiteLanguage $language): array => [
                    'languageId' => $language->getLanguageId(),
                    'title' => $language->getTitle(),
                    'locale' => (string)$language->getLocale(),
                    'base' => (string)$language->getBase(),
                    'fallbackType' => $language->getFallbackType(),
                ],
                $languages
            )),
        ];
    }
}
