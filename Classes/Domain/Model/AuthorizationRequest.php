<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Model;

readonly class AuthorizationRequest
{
    public function __construct(
        private string $clientIdentifier,
        private string $clientName,
        private string $redirectUri,
        private string $codeChallenge,
        private string $state,
        private string $scope,
        private string $resource,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['clientIdentifier'] ?? ''),
            (string)($data['clientName'] ?? ''),
            (string)($data['redirectUri'] ?? ''),
            (string)($data['codeChallenge'] ?? ''),
            (string)($data['state'] ?? ''),
            (string)($data['scope'] ?? ''),
            (string)($data['resource'] ?? '')
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'clientIdentifier' => $this->clientIdentifier,
            'clientName' => $this->clientName,
            'redirectUri' => $this->redirectUri,
            'codeChallenge' => $this->codeChallenge,
            'state' => $this->state,
            'scope' => $this->scope,
            'resource' => $this->resource,
        ];
    }

    public function getClientIdentifier(): string
    {
        return $this->clientIdentifier;
    }

    public function getClientName(): string
    {
        return $this->clientName;
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function getRedirectHost(): string
    {
        return (string)parse_url($this->redirectUri, PHP_URL_HOST);
    }

    public function getCodeChallenge(): string
    {
        return $this->codeChallenge;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getResource(): string
    {
        return $this->resource;
    }

    public function isWriteAccessRequested(): bool
    {
        return AccessScope::isReadOnly(AccessScope::fromScopeString($this->scope)) === false;
    }
}
