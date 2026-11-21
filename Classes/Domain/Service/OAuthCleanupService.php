<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Repository\BackendUserRepository;
use In2code\In2mcp\Domain\Repository\OAuthClientRepository;
use In2code\In2mcp\Domain\Repository\OAuthGrantRepository;

/**
 * Removes what can not be used any more. Nothing of it is a security measure - an expired token is refused anyway -
 * it keeps the tables small, which matter as the registration is open to everybody.
 */
readonly class OAuthCleanupService
{
    // A client that registered itself and got no consent within a day is not coming back
    public const UNUSED_CLIENT_LIFETIME = 86400;

    public function __construct(
        private OAuthGrantRepository $grantRepository,
        private OAuthClientRepository $clientRepository,
        private BackendUserRepository $backendUserRepository,
    ) {
    }

    /**
     * @return array{expiredGrants: int, orphanedGrants: int, unusedClients: int}
     * @throws Exception
     */
    public function cleanup(int $now): array
    {
        $expiredGrants = $this->grantRepository->deleteExpired($now);
        $orphanedGrants = $this->deleteGrantsOfMissingClients() + $this->deleteGrantsOfMissingUsers();
        $unusedClients = $this->clientRepository->deleteByClientIdentifiers(array_values(array_diff(
            $this->clientRepository->findRegisteredClientIdentifiersCreatedBefore($now - self::UNUSED_CLIENT_LIFETIME),
            $this->grantRepository->findClientIdentifiers()
        )));

        return [
            'expiredGrants' => $expiredGrants,
            'orphanedGrants' => $orphanedGrants,
            'unusedClients' => $unusedClients,
        ];
    }

    /**
     * @throws Exception
     */
    private function deleteGrantsOfMissingClients(): int
    {
        return $this->grantRepository->deleteByClientIdentifiers(array_values(array_diff(
            $this->grantRepository->findClientIdentifiers(),
            $this->clientRepository->findClientIdentifiers()
        )));
    }

    /**
     * @throws Exception
     */
    private function deleteGrantsOfMissingUsers(): int
    {
        $backendUserIdentifiers = $this->grantRepository->findBackendUserIdentifiers();
        return $this->grantRepository->deleteByBackendUserIdentifiers(array_values(array_diff(
            $backendUserIdentifiers,
            $this->backendUserRepository->findExistingUids($backendUserIdentifiers)
        )));
    }
}
