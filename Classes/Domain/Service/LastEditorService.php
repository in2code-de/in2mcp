<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Repository\BackendUserActivityRepository;
use In2code\In2mcp\Domain\Repository\ContentRepository;
use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Domain\Repository\RecentChangeRepository;
use In2code\In2mcp\Exception\UserNotFoundException;

/**
 * Who changed a record last is information about backend users, so it is given to administrators only
 */
class LastEditorService
{
    /**
     * A history entry is written in the same request as the change, so its time may differ by a moment
     */
    private const HISTORY_TOLERANCE_SECONDS = 60;

    public function __construct(
        private readonly BackendUserActivityRepository $backendUserActivityRepository,
        private readonly RecentChangeRepository $recentChangeRepository,
        private readonly BackendUserService $backendUserService,
    ) {
    }

    /**
     * @throws UserNotFoundException
     */
    public function isAvailable(): bool
    {
        return $this->backendUserService->isAdmin();
    }

    /**
     * A page counts as changed by whoever changed it, one of its translations or one of its content elements last
     *
     * @param array<int, int> $lastChangeByPage page uid => timestamp of its last change
     * @return array<int, string> page uid => username
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function findEditorsOfPages(array $lastChangeByPage): array
    {
        if ($this->isAvailable() === false || $lastChangeByPage === []) {
            return [];
        }

        $pageUids = array_keys($lastChangeByPage);
        $pageUidsByRecord = [
            PageRepository::TABLE_NAME => array_combine($pageUids, $pageUids)
                + $this->recentChangeRepository->findRecordsOfPages(PageRepository::TABLE_NAME, $pageUids),
            ContentRepository::TABLE_NAME => $this->recentChangeRepository->findRecordsOfPages(
                ContentRepository::TABLE_NAME,
                $pageUids
            ),
        ];
        return $this->findEditors($pageUidsByRecord, min($lastChangeByPage));
    }

    /**
     * @param array<int, int> $changeByContent content uid => timestamp of its change
     * @return array<int, string> content uid => username
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function findEditorsOfContent(array $changeByContent): array
    {
        if ($this->isAvailable() === false || $changeByContent === []) {
            return [];
        }

        $contentUids = array_keys($changeByContent);
        return $this->findEditors(
            [ContentRepository::TABLE_NAME => array_combine($contentUids, $contentUids)],
            min($changeByContent)
        );
    }

    /**
     * @param list<array{tablename: string, recuid: int, userid: int}> $historyEntries newest first
     * @param array<string, array<int, int>> $keysByRecord table => record uid => key the editor is reported for
     * @return array<int, int> key => uid of the backend user of the newest entry
     */
    public function pickNewestEditors(array $historyEntries, array $keysByRecord): array
    {
        $editors = [];
        foreach ($historyEntries as $entry) {
            $key = $keysByRecord[$entry['tablename']][$entry['recuid']] ?? null;
            if ($key !== null && array_key_exists($key, $editors) === false) {
                $editors[$key] = $entry['userid'];
            }
        }
        return $editors;
    }

    /**
     * @param array<string, array<int, int>> $keysByRecord
     * @return array<int, string>
     * @throws Exception
     */
    private function findEditors(array $keysByRecord, int $oldestChange): array
    {
        $historyEntries = $this->backendUserActivityRepository->findHistoryOfRecords(
            array_map(static fn(array $keys): array => array_keys($keys), $keysByRecord),
            $oldestChange - self::HISTORY_TOLERANCE_SECONDS
        );
        $editorUids = $this->pickNewestEditors($historyEntries, $keysByRecord);
        $usernames = $this->backendUserActivityRepository->findUsernames(array_values(array_unique($editorUids)));

        $editors = [];
        foreach ($editorUids as $key => $userUid) {
            $editors[$key] = $usernames[$userUid] ?? ('#' . $userUid);
        }
        return $editors;
    }
}
