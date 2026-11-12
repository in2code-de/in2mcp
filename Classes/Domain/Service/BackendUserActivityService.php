<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Repository\BackendUserActivityRepository;
use In2code\In2mcp\Exception\AdministratorRequiredException;
use In2code\In2mcp\Exception\UserNotFoundException;
use JsonException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\History\RecordHistoryStore;

class BackendUserActivityService
{
    private const DATE_FORMAT = DATE_ATOM;

    private const ACTION_NAMES = [
        RecordHistoryStore::ACTION_ADD => 'created',
        RecordHistoryStore::ACTION_MODIFY => 'changed',
        RecordHistoryStore::ACTION_MOVE => 'moved',
        RecordHistoryStore::ACTION_DELETE => 'deleted',
        RecordHistoryStore::ACTION_UNDELETE => 'restored',
        RecordHistoryStore::ACTION_STAGECHANGE => 'stage changed',
        RecordHistoryStore::ACTION_PUBLISH => 'published',
    ];

    public function __construct(
        private readonly BackendUserActivityRepository $backendUserActivityRepository,
        private readonly BackendUserService $backendUserService,
        private readonly TcaService $tcaService,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     * @throws AdministratorRequiredException
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function getBackendUsers(): array
    {
        $this->assertAdministrator();
        $lastChanges = $this->backendUserActivityRepository->findLastChangeTimesByUser();

        return array_map(
            fn(array $user): array => [
                'uid' => (int)$user['uid'],
                'username' => (string)$user['username'],
                'realName' => (string)$user['realName'],
                'admin' => (bool)$user['admin'],
                'disabled' => (bool)$user['disable'],
                'lastLogin' => $this->formatTimestamp((int)$user['lastlogin']),
                'mfaProviders' => $this->getActiveMfaProviders((string)$user['mfa']),
                'lastChange' => $this->formatTimestamp($lastChanges[(int)$user['uid']] ?? 0),
            ],
            $this->backendUserActivityRepository->findUsers()
        );
    }

    /**
     * @return list<array<string, mixed>>
     * @throws AdministratorRequiredException
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function getRecentChanges(int $userUid, string $table, int $limit): array
    {
        $this->assertAdministrator();
        $changes = $this->backendUserActivityRepository->findRecentChanges($userUid, $table, $limit);
        $usernames = $this->backendUserActivityRepository->findUsernames(
            array_values(array_unique(array_map('intval', array_column($changes, 'userid'))))
        );

        return array_map(
            fn(array $change): array => [
                'changed' => $this->formatTimestamp((int)$change['tstamp']),
                'action' => self::ACTION_NAMES[(int)$change['actiontype']] ?? 'unknown',
                'userUid' => (int)$change['userid'],
                'username' => $usernames[(int)$change['userid']] ?? '',
                'table' => (string)$change['tablename'],
                'tableLabel' => $this->tcaService->getTableLabel((string)$change['tablename']),
                'recordUid' => (int)$change['recuid'],
                'recordTitle' => $this->getRecordTitle((string)$change['tablename'], (int)$change['recuid']),
                'changedFields' => $this->getChangedFields((string)$change['history_data']),
            ],
            $changes
        );
    }

    /**
     * Only the names of the active providers - the stored configuration holds the secrets of the providers
     *
     * @return list<string>
     */
    public function getActiveMfaProviders(string $mfaConfiguration): array
    {
        try {
            $providers = json_decode($mfaConfiguration, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $providers = [];
        }

        $activeProviders = [];
        foreach (is_array($providers) ? $providers : [] as $identifier => $configuration) {
            if (is_array($configuration) && ($configuration['active'] ?? false) === true) {
                $activeProviders[] = (string)$identifier;
            }
        }
        return $activeProviders;
    }

    /**
     * Names of the fields of a change, never their values
     *
     * @return list<string>
     */
    public function getChangedFields(string $historyData): array
    {
        try {
            $data = json_decode($historyData, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $data = [];
        }

        $newRecord = is_array($data) ? ($data['newRecord'] ?? []) : [];
        return is_array($newRecord) ? array_map('strval', array_keys($newRecord)) : [];
    }

    /**
     * @throws AdministratorRequiredException
     * @throws UserNotFoundException
     */
    private function assertAdministrator(): void
    {
        if ($this->backendUserService->isAdmin() === false) {
            throw new AdministratorRequiredException(
                'Only administrators may read information about backend users and their changes',
                1794496800
            );
        }
    }

    private function getRecordTitle(string $table, int $uid): ?string
    {
        // BackendUtility is the only API for the label of a record, there is no injectable service for it
        $record = $this->tcaService->isTable($table) ? BackendUtility::getRecord($table, $uid) : null;
        return $record === null ? null : BackendUtility::getRecordTitle($table, $record);
    }

    private function formatTimestamp(int $timestamp): ?string
    {
        return $timestamp > 0 ? date(self::DATE_FORMAT, $timestamp) : null;
    }
}
