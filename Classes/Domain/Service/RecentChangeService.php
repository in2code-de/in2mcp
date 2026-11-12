<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Repository\ContentRepository;
use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Domain\Repository\RecentChangeRepository;
use In2code\In2mcp\Exception\UserNotFoundException;

class RecentChangeService
{
    private const DATE_FORMAT = DATE_ATOM;

    /**
     * Key in the result => table (null for the table of the record) and field the value is taken from
     */
    private const FIELD_SOURCES = [
        'title' => [null, 'title'],
        'nav_title' => [null, 'nav_title'],
        'slug' => [null, 'slug'],
        'doktype' => [null, 'doktype'],
        'doktypeName' => [null, 'doktype'],
        'hidden' => [null, 'hidden'],
        'CType' => [null, 'CType'],
        'contentTypeName' => [null, 'CType'],
        'header' => [null, 'header'],
        'colPos' => [null, 'colPos'],
        'sys_language_uid' => [null, 'sys_language_uid'],
        'pageTitle' => [PageRepository::TABLE_NAME, 'title'],
        'pageSlug' => [PageRepository::TABLE_NAME, 'slug'],
    ];

    public function __construct(
        private readonly RecentChangeRepository $recentChangeRepository,
        private readonly PageRepository $pageRepository,
        private readonly BackendUserService $backendUserService,
        private readonly TcaService $tcaService,
    ) {
    }

    /**
     * A page counts as changed when its properties, a translation of it or one of its content elements changed
     *
     * @return list<array<string, mixed>>
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function getRecentlyChangedPages(int $rootPageUid, int $limit): array
    {
        $pageUids = $this->getPageUidsInScope($rootPageUid);
        $ranking = $this->rankByLastChange(
            $this->recentChangeRepository->findPageChangeTimes($pageUids, $limit),
            $this->recentChangeRepository->findContentChangeTimes($pageUids, $limit),
            $limit
        );
        $pages = $this->recentChangeRepository->findPagesByUids(array_keys($ranking));

        $result = [];
        foreach ($ranking as $pageUid => $changes) {
            if (isset($pages[$pageUid])) {
                $result[] = $this->buildPage($pages[$pageUid], $changes);
            }
        }
        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function getRecentlyChangedContent(int $rootPageUid, ?int $languageId, int $limit): array
    {
        $contentTypeNames = array_column($this->tcaService->getContentTypes(), 'label', 'value');
        return array_map(
            fn(array $contentElement): array => $this->removeUnreadableFields(ContentRepository::TABLE_NAME, [
                'uid' => (int)$contentElement['uid'],
                'pid' => (int)$contentElement['pid'],
                'CType' => (string)$contentElement['CType'],
                'contentTypeName' => (string)($contentTypeNames[$contentElement['CType']] ?? $contentElement['CType']),
                'header' => (string)$contentElement['header'],
                'colPos' => (int)$contentElement['colPos'],
                'sys_language_uid' => (int)$contentElement['sys_language_uid'],
                'hidden' => (int)$contentElement['hidden'],
                'changed' => $this->formatTimestamp((int)$contentElement['tstamp']),
                'pageTitle' => (string)$contentElement['page_title'],
                'pageSlug' => (string)$contentElement['page_slug'],
            ]),
            $this->recentChangeRepository->findRecentContent(
                $this->getPageUidsInScope($rootPageUid),
                $languageId,
                $limit
            )
        );
    }

    /**
     * Both lists only need to hold their own top entries: a page among the overall top entries is always among
     * the top entries of at least one of them.
     *
     * @param array<int, int> $pageChangeTimes page uid => timestamp
     * @param array<int, int> $contentChangeTimes page uid => timestamp
     * @return array<int, array{lastChanged: int, pageChanged: ?int, contentChanged: ?int}> ordered, newest first
     */
    public function rankByLastChange(array $pageChangeTimes, array $contentChangeTimes, int $limit): array
    {
        $ranking = [];
        foreach (array_keys($pageChangeTimes + $contentChangeTimes) as $pageUid) {
            $pageChanged = $pageChangeTimes[$pageUid] ?? null;
            $contentChanged = $contentChangeTimes[$pageUid] ?? null;
            $ranking[$pageUid] = [
                'lastChanged' => max($pageChanged ?? 0, $contentChanged ?? 0),
                'pageChanged' => $pageChanged,
                'contentChanged' => $contentChanged,
            ];
        }
        uksort(
            $ranking,
            static fn(int $left, int $right): int => [$ranking[$right]['lastChanged'], $right]
                <=> [$ranking[$left]['lastChanged'], $left]
        );
        return array_slice($ranking, 0, max($limit, 0), true);
    }

    /**
     * @return list<int>|null null when the whole installation is in scope
     * @throws Exception
     * @throws UserNotFoundException
     */
    private function getPageUidsInScope(int $rootPageUid): ?array
    {
        $pageUids = null;
        if ($rootPageUid > 0) {
            $pageUids = $this->backendUserService->isInWebMount($rootPageUid)
                ? $this->pageRepository->findSubtreeUids($rootPageUid)
                : [];
        } elseif ($this->backendUserService->hasFullTreeAccess() === false) {
            $pageUids = [];
            foreach ($this->backendUserService->getWebMounts() as $mountUid) {
                $pageUids = array_merge($pageUids, $this->pageRepository->findSubtreeUids($mountUid));
            }
            $pageUids = array_values(array_unique($pageUids));
        }
        return $pageUids;
    }

    /**
     * @param array<string, mixed> $page
     * @param array{lastChanged: int, pageChanged: ?int, contentChanged: ?int} $changes
     * @return array<string, mixed>
     */
    private function buildPage(array $page, array $changes): array
    {
        return $this->removeUnreadableFields(PageRepository::TABLE_NAME, [
            'uid' => (int)$page['uid'],
            'pid' => (int)$page['pid'],
            'title' => (string)$page['title'],
            'nav_title' => (string)$page['nav_title'],
            'slug' => (string)$page['slug'],
            'doktype' => (int)$page['doktype'],
            'doktypeName' => $this->tcaService->getPageTypeName((int)$page['doktype']),
            'hidden' => (int)$page['hidden'],
            'lastChanged' => $this->formatTimestamp($changes['lastChanged']),
            'pagePropertiesChanged' => $this->formatNullableTimestamp($changes['pageChanged']),
            'contentChanged' => $this->formatNullableTimestamp($changes['contentChanged']),
        ]);
    }

    /**
     * Same rule as for every other read: an exclude field the user may not see in the backend is left out
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     * @throws UserNotFoundException
     */
    private function removeUnreadableFields(string $table, array $record): array
    {
        foreach (self::FIELD_SOURCES as $key => [$sourceTable, $sourceField]) {
            if (array_key_exists($key, $record)
                && $this->tcaService->isFieldReadable($sourceTable ?? $table, $sourceField) === false) {
                unset($record[$key]);
            }
        }
        return $record;
    }

    private function formatNullableTimestamp(?int $timestamp): ?string
    {
        return $timestamp === null ? null : $this->formatTimestamp($timestamp);
    }

    private function formatTimestamp(int $timestamp): string
    {
        return date(self::DATE_FORMAT, $timestamp);
    }
}
