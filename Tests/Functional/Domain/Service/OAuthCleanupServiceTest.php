<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Domain\Service;

use In2code\In2mcp\Domain\Model\OAuthClient;
use In2code\In2mcp\Domain\Repository\OAuthClientRepository;
use In2code\In2mcp\Domain\Repository\OAuthGrantRepository;
use In2code\In2mcp\Domain\Service\OAuthCleanupService;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class OAuthCleanupServiceTest extends AbstractFunctionalTestCase
{
    private const NOW = 1800000000;
    private const DELETED_USER = 99;

    #[Test]
    public function cleanupRemovesWhatCanNotBeUsedAnyMore(): void
    {
        $old = self::NOW - OAuthCleanupService::UNUSED_CLIENT_LIFETIME - 1;
        $this->createClient('used', OAuthClient::TYPE_DYNAMIC, $old);
        $this->createClient('unused-old', OAuthClient::TYPE_DYNAMIC, $old);
        $this->createClient('unused-old-document', OAuthClient::TYPE_METADATA_DOCUMENT, $old);
        $this->createClient('unused-new', OAuthClient::TYPE_DYNAMIC, self::NOW - 60);
        $this->createClient('manual', OAuthClient::TYPE_MANUAL, $old);
        $this->createGrant('used', self::EDITOR, self::NOW + 3600);
        $this->createGrant('used', self::ADMIN, self::NOW - 1);
        $this->createGrant('deleted-client', self::EDITOR, self::NOW + 3600);
        $this->createGrant('used', self::DELETED_USER, self::NOW + 3600);

        $result = $this->get(OAuthCleanupService::class)->cleanup(self::NOW);

        self::assertSame(['expiredGrants' => 1, 'orphanedGrants' => 2, 'unusedClients' => 2], $result);
        self::assertSame(
            ['manual', 'unused-new', 'used'],
            $this->sorted($this->get(OAuthClientRepository::class)->findClientIdentifiers())
        );
        self::assertSame([self::EDITOR], $this->get(OAuthGrantRepository::class)->findBackendUserIdentifiers());
    }

    private function createClient(string $clientIdentifier, string $registrationType, int $crdate): void
    {
        $this->get(OAuthClientRepository::class)->insert(
            new OAuthClient($clientIdentifier, $clientIdentifier, ['https://a.example/cb'], $registrationType)
        );
        $this->get(ConnectionPool::class)
            ->getConnectionForTable(OAuthClientRepository::TABLE_NAME)
            ->update(OAuthClientRepository::TABLE_NAME, ['crdate' => $crdate], ['client_id' => $clientIdentifier]);
    }

    private function createGrant(string $clientIdentifier, int $backendUserIdentifier, int $refreshTokenExpires): void
    {
        $this->get(OAuthGrantRepository::class)->insert([
            'be_user' => $backendUserIdentifier,
            'client_id' => $clientIdentifier,
            'access_token_hash' => hash('sha256', uniqid('', true)),
            'refresh_token_hash' => hash('sha256', uniqid('', true)),
            'refresh_token_expires' => $refreshTokenExpires,
        ]);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);
        return $values;
    }
}
