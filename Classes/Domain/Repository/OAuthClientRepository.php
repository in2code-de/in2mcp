<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Repository;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Model\OAuthClient;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

readonly class OAuthClientRepository
{
    public const TABLE_NAME = 'tx_in2mcp_oauth_client';

    public function __construct(private ConnectionPool $connectionPool)
    {
    }

    /**
     * @throws Exception
     */
    public function insert(OAuthClient $client): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE_NAME)->insert(self::TABLE_NAME, [
            'client_id' => $client->getClientIdentifier(),
            'client_name' => $client->getClientName(),
            'redirect_uris' => $client->getRedirectUrisAsText(),
            'registration_type' => $client->getRegistrationType(),
            'client_secret_hash' => $client->getSecretHash(),
            'crdate' => time(),
        ]);
    }

    /**
     * @throws Exception
     */
    public function save(OAuthClient $client): void
    {
        if ($this->findByClientIdentifier($client->getClientIdentifier()) === null) {
            $this->insert($client);
        } else {
            $this->connectionPool->getConnectionForTable(self::TABLE_NAME)->update(
                self::TABLE_NAME,
                [
                    'client_name' => $client->getClientName(),
                    'redirect_uris' => $client->getRedirectUrisAsText(),
                ],
                ['client_id' => $client->getClientIdentifier()]
            );
        }
    }

    /**
     * @throws Exception
     */
    public function findByClientIdentifier(string $clientIdentifier): ?OAuthClient
    {
        if ($clientIdentifier === '') {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq('client_id', $queryBuilder->createNamedParameter($clientIdentifier))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : OAuthClient::fromRow($row);
    }

    /**
     * @return list<string>
     * @throws Exception
     */
    public function findClientIdentifiers(): array
    {
        return array_map('strval', $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME)
            ->select('client_id')
            ->from(self::TABLE_NAME)
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * Clients that registered themselves, not the ones an administrator created
     *
     * @return list<string>
     * @throws Exception
     */
    public function findRegisteredClientIdentifiersCreatedBefore(int $timestamp): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        return array_map('strval', $queryBuilder
            ->select('client_id')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->in(
                    'registration_type',
                    $queryBuilder->createNamedParameter(
                        [OAuthClient::TYPE_DYNAMIC, OAuthClient::TYPE_METADATA_DOCUMENT],
                        Connection::PARAM_STR_ARRAY
                    )
                ),
                $queryBuilder->expr()->lt(
                    'crdate',
                    $queryBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * @param list<string> $clientIdentifiers
     * @throws Exception
     */
    public function deleteByClientIdentifiers(array $clientIdentifiers): int
    {
        $deleted = 0;
        if ($clientIdentifiers !== []) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
            $deleted = $queryBuilder
                ->delete(self::TABLE_NAME)
                ->where($queryBuilder->expr()->in(
                    'client_id',
                    $queryBuilder->createNamedParameter($clientIdentifiers, Connection::PARAM_STR_ARRAY)
                ))
                ->executeStatement();
        }
        return $deleted;
    }
}
