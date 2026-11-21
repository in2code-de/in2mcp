<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Domain\Model\OAuthClient;
use In2code\In2mcp\Exception\FileImportException;
use In2code\In2mcp\Exception\OAuthException;
use JsonException;
use Throwable;

/**
 * Client ID Metadata Documents: the client id is a https url, and the document behind it names the client and its
 * redirect uris. It is fetched through the same guard as a file import, so a client id can not make the server
 * request anything in the internal network.
 */
readonly class ClientMetadataDocumentService
{
    private const MAXIMUM_SIZE = 5120;
    private const TIMEOUT = 5;
    private const AUTHENTICATION_METHOD = 'none';

    public function __construct(
        private UrlValidationService $urlValidationService,
        private GuardedRequestService $guardedRequestService,
        private ClientRegistrationService $clientRegistrationService,
    ) {
    }

    public static function isMetadataDocumentUrl(string $clientIdentifier): bool
    {
        $parts = parse_url($clientIdentifier);
        $path = (string)($parts['path'] ?? '');
        return $parts !== false
            && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && ($parts['host'] ?? '') !== ''
            && $path !== '' && $path !== '/'
            && isset($parts['fragment']) === false
            && isset($parts['user']) === false
            && preg_match('~/\.{1,2}(/|$)~', $path) !== 1;
    }

    /**
     * @throws OAuthException
     */
    public function fetch(string $clientIdentifier): OAuthClient
    {
        $document = $this->getDocument($clientIdentifier);
        if (($document['client_id'] ?? null) !== $clientIdentifier) {
            throw $this->getException('client_id of the document is not the url of the document', 1763770101);
        }
        if (($document['token_endpoint_auth_method'] ?? self::AUTHENTICATION_METHOD) !== self::AUTHENTICATION_METHOD) {
            throw $this->getException('only public clients with token_endpoint_auth_method "none"', 1763770102);
        }

        $redirectUris = $document['redirect_uris'] ?? null;
        $isValid = is_array($redirectUris) && $redirectUris !== [];
        foreach (is_array($redirectUris) ? $redirectUris : [] as $redirectUri) {
            $isValid = $isValid
                && is_string($redirectUri)
                && $this->clientRegistrationService->isAllowedRedirectUri($redirectUri);
        }
        if ($isValid === false) {
            throw $this->getException('redirect_uris has to list https or localhost urls', 1763770103);
        }

        return new OAuthClient(
            $clientIdentifier,
            OAuthClient::cleanName($document['client_name'] ?? ''),
            array_values(array_unique($redirectUris)),
            OAuthClient::TYPE_METADATA_DOCUMENT
        );
    }

    /**
     * @return array<string, mixed>
     * @throws OAuthException
     */
    private function getDocument(string $clientIdentifier): array
    {
        try {
            $response = $this->guardedRequestService->request(
                $this->urlValidationService->assertClientMetadataUrl($clientIdentifier),
                self::MAXIMUM_SIZE,
                ['timeout' => self::TIMEOUT, 'headers' => ['Accept' => 'application/json']]
            );
            $document = $response->getStatusCode() === 200
                ? json_decode((string)$response->getBody(), true, 16, JSON_THROW_ON_ERROR)
                : null;
        } catch (FileImportException $exception) {
            throw $this->getException($exception->getMessage(), 1763770104);
        } catch (JsonException) {
            $document = null;
        } catch (Throwable) {
            throw $this->getException('the document could not be fetched', 1763770105);
        }

        if (is_array($document) === false) {
            throw $this->getException('the url does not answer with a json document', 1763770106);
        }
        return $document;
    }

    private function getException(string $reason, int $code): OAuthException
    {
        return new OAuthException(
            OAuthException::INVALID_CLIENT,
            'The client metadata document is not valid: ' . $reason,
            $code
        );
    }
}
