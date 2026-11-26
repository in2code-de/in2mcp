<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;
use Throwable;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Routing\RouteNotFoundException;
use TYPO3\CMS\Core\Routing\SiteMatcher;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Follows the order of the frontend: site and language first, then a redirect, then the page of the slug. A page
 * the user may not see is reported like a missing one, so the answer reveals no uids.
 */
class UrlResolutionService
{
    private const DEFAULT_LANGUAGE = 0;
    private const STATUS_REDIRECT = 'redirect';
    private const STATUS_PAGE = 'page';
    private const STATUS_UNAVAILABLE = 'unavailable';
    private const STATUS_NOT_FOUND = 'not_found';

    public function __construct(
        private readonly SiteMatcher $siteMatcher,
        private readonly PageRepository $pageRepository,
        private readonly RecordAccessService $recordAccessService,
        private readonly RedirectMatchService $redirectMatchService,
        private readonly LinkTargetService $linkTargetService,
        private readonly PageAvailabilityService $pageAvailabilityService,
    ) {
    }

    /**
     * @return array<string, mixed>
     * @throws Exception
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function resolve(string $url): array
    {
        $request = $this->createRequest($url);
        $routeResult = $this->siteMatcher->matchRequest($request);
        $site = $routeResult instanceof SiteRouteResult ? $routeResult->getSite() : null;
        $language = $routeResult instanceof SiteRouteResult ? $routeResult->getLanguage() : null;

        $result = [
            'url' => (string)$request->getUri(),
            'site' => $site instanceof Site
                ? ['identifier' => $site->getIdentifier(), 'base' => (string)$site->getBase()]
                : null,
            'language' => $language === null ? null : $this->describeLanguage($language),
            'redirect' => $this->findRedirect($request),
            'page' => null,
        ];
        if ($site instanceof Site && $language !== null && $routeResult instanceof SiteRouteResult) {
            $result['page'] = $this->resolvePage($site, $language, $request, $routeResult);
        }

        return ['status' => $this->getStatus($result)] + $result;
    }

    /**
     * @throws ToolExecutionException
     */
    private function createRequest(string $url): ServerRequest
    {
        try {
            $uri = new Uri($url);
        } catch (Throwable) {
            $uri = null;
        }
        if ($uri === null || $uri->getHost() === '') {
            throw new ToolExecutionException(
                'The url "' . $url . '" can not be resolved - pass an absolute url with host, for example'
                . ' "https://www.example.org/about/"',
                1764151200
            );
        }
        parse_str($uri->getQuery(), $queryParameters);
        return (new ServerRequest($uri, 'GET'))->withQueryParams($queryParameters);
    }

    /**
     * @return array<string, mixed>
     */
    private function describeLanguage(SiteLanguage $language): array
    {
        return [
            'languageId' => $language->getLanguageId(),
            'title' => $language->getTitle(),
            'base' => (string)$language->getBase(),
            'enabled' => $language->isEnabled(),
            'fallbackType' => $language->getFallbackType(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     * @throws Exception
     * @throws UserNotFoundException
     */
    private function findRedirect(ServerRequest $request): ?array
    {
        $uri = $request->getUri();
        $redirect = $this->redirectMatchService->findRedirect($uri->getHost(), $uri->getPath(), $uri->getQuery());
        if ($redirect !== null) {
            $redirect['targetLink'] = $this->linkTargetService->describeLink($redirect['target']);
        }
        return $redirect;
    }

    /**
     * @return array<string, mixed>
     * @throws Exception
     * @throws UserNotFoundException
     */
    private function resolvePage(
        Site $site,
        SiteLanguage $language,
        ServerRequest $request,
        SiteRouteResult $routeResult
    ): array {
        $routedPageUid = $this->matchPageUid($site, $request, $routeResult);
        $pageUid = $routedPageUid > 0
            ? $routedPageUid
            : $this->findPageUidOfSlugOutsideOfRouting($site, $language, $routeResult);
        $page = $this->findReadablePage($pageUid, $language);
        $description = $page === null
            ? $this->describeMissingPage($site, $language, $request, $routeResult)
            : [
                'found' => true,
                'pageUid' => $pageUid,
                'recordUid' => (int)($page['uid'] ?? $pageUid),
                'title' => (string)($page['title'] ?? ''),
                'slug' => (string)($page['slug'] ?? ''),
                'reasonsForMissingOutput' => $this->getReasonsForMissingOutput($page, $language, $routedPageUid > 0),
            ];
        return $description;
    }

    /**
     * @param array<string, mixed> $page
     * @return list<string>
     */
    private function getReasonsForMissingOutput(array $page, SiteLanguage $language, bool $isRouted): array
    {
        $reasons = $this->pageAvailabilityService->getReasonsForMissingOutput($page, $language);
        if ($isRouted === false && $reasons === []) {
            $reasons[] = 'The page has this slug, but the routing of TYPO3 does not deliver it for this url';
        }
        return $reasons;
    }

    private function matchPageUid(Site $site, ServerRequest $request, SiteRouteResult $routeResult): int
    {
        try {
            $pageArguments = $site->getRouter()->matchRequest($request, $routeResult);
            $pageUid = $pageArguments instanceof PageArguments ? $pageArguments->getPageId() : 0;
        } catch (RouteNotFoundException) {
            $pageUid = 0;
        }
        return $pageUid;
    }

    /**
     * TYPO3 14.3.12 and newer leave pages without output (folder, spacer, recycler) out of the routing, while
     * earlier versions route them and answer with a 404 later - the page is looked up by its slug to explain both
     *
     * @throws Exception
     * @throws UserNotFoundException
     */
    private function findPageUidOfSlugOutsideOfRouting(
        Site $site,
        SiteLanguage $language,
        SiteRouteResult $routeResult
    ): int {
        $pageUids = array_filter(
            $this->pageRepository->findUidsBySlug($routeResult->getTail(), $language->getLanguageId()),
            fn (int $pageUid): bool => $this->isPageOfSite($pageUid, $site)
        );
        return (int)(reset($pageUids) ?: 0);
    }

    private function isPageOfSite(int $pageUid, Site $site): bool
    {
        try {
            $isPageOfSite = $this->siteMatcher->matchByPageId($pageUid)->getRootPageId() === $site->getRootPageId();
        } catch (Throwable) {
            $isPageOfSite = false;
        }
        return $isPageOfSite;
    }

    /**
     * The page record of the language of the url - the translation for a language above 0
     *
     * @return array<string, mixed>|null
     * @throws Exception
     * @throws UserNotFoundException
     */
    private function findReadablePage(int $pageUid, SiteLanguage $language): ?array
    {
        $page = $pageUid > 0 ? $this->pageRepository->findByUid($pageUid) : null;
        if ($page !== null && $language->getLanguageId() !== self::DEFAULT_LANGUAGE) {
            $page = $this->pageRepository->findTranslation($pageUid, $language->getLanguageId());
        }
        return $page !== null && $this->recordAccessService->isRecordReadable(PageRepository::TABLE_NAME, $page)
            ? $page
            : null;
    }

    /**
     * @return array<string, mixed>
     * @throws Exception
     * @throws UserNotFoundException
     */
    private function describeMissingPage(
        Site $site,
        SiteLanguage $language,
        ServerRequest $request,
        SiteRouteResult $routeResult
    ): array {
        $description = [
            'found' => false,
            'message' => 'No page this backend user may see has the slug "/' . ltrim($routeResult->getTail(), '/')
                . '" in the language "' . $language->getTitle() . '"',
        ];
        if ($language->getLanguageId() !== self::DEFAULT_LANGUAGE) {
            $defaultLanguage = $site->getDefaultLanguage();
            $defaultRouteResult = new SiteRouteResult(
                $routeResult->getUri(),
                $site,
                $defaultLanguage,
                $routeResult->getTail()
            );
            $pageUidOfDefaultLanguage = $this->matchPageUid($site, $request, $defaultRouteResult);
            if ($this->findReadablePage($pageUidOfDefaultLanguage, $defaultLanguage) !== null) {
                $description['pageOfDefaultLanguage'] = $pageUidOfDefaultLanguage;
                $description['message'] .= '. The page ' . $pageUidOfDefaultLanguage . ' has this slug in'
                    . ' the default language - a url of the language "' . $language->getTitle() . '" uses the slug'
                    . ' of the translation, look at it with "get_page" and "languageId" '
                    . $language->getLanguageId();
            }
        }
        return $description;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function getStatus(array $result): string
    {
        $status = self::STATUS_NOT_FOUND;
        if ($result['redirect'] !== null) {
            $status = self::STATUS_REDIRECT;
        } elseif (($result['page']['found'] ?? false) === true) {
            $status = $result['page']['reasonsForMissingOutput'] === []
                ? self::STATUS_PAGE
                : self::STATUS_UNAVAILABLE;
        }
        return $status;
    }
}
