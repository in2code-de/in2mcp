<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Model\OAuthClient;
use In2code\In2mcp\Domain\Repository\OAuthClientRepository;
use In2code\In2mcp\Exception\OAuthException;

readonly class OAuthClientService
{
    public function __construct(
        private OAuthClientRepository $clientRepository,
        private ClientMetadataDocumentService $clientMetadataDocumentService,
        private ConfigurationService $configurationService,
    ) {
    }

    /**
     * A client id that is a url is looked up at the client on every authorization, so a changed document counts
     * at once. It is stored for the token endpoint, which does not fetch anything.
     *
     * @throws OAuthException
     * @throws Exception
     */
    public function findForAuthorization(string $clientIdentifier): ?OAuthClient
    {
        if ($this->isMetadataDocumentClient($clientIdentifier) === false) {
            return $this->clientRepository->findByClientIdentifier($clientIdentifier);
        }

        $client = $this->clientMetadataDocumentService->fetch($clientIdentifier);
        $this->clientRepository->save($client);
        return $client;
    }

    private function isMetadataDocumentClient(string $clientIdentifier): bool
    {
        return $this->configurationService->isClientMetadataDocumentActivated()
            && ClientMetadataDocumentService::isMetadataDocumentUrl($clientIdentifier);
    }
}
