<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Repository;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Model\OAuthClient;
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
}
