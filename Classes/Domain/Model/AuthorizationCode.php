<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Model;

readonly class AuthorizationCode
{
    public function __construct(
        private string $clientIdentifier,
        private string $redirectUri,
        private string $codeChallenge,
        private string $scope,
        private string $resource,
        private int $backendUserIdentifier,
        private int $expires,
    ) {
    }

    public static function fromRequest(
        AuthorizationRequest $request,
        int $backendUserIdentifier,
        string $scope,
        int $expires
    ): self {
        return new self(
            $request->getClientIdentifier(),
            $request->getRedirectUri(),
            $request->getCodeChallenge(),
            $scope,
            $request->getResource(),
            $backendUserIdentifier,
            $expires
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['clientIdentifier'] ?? ''),
            (string)($data['redirectUri'] ?? ''),
            (string)($data['codeChallenge'] ?? ''),
            (string)($data['scope'] ?? ''),
            (string)($data['resource'] ?? ''),
            (int)($data['backendUserIdentifier'] ?? 0),
            (int)($data['expires'] ?? 0)
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'clientIdentifier' => $this->clientIdentifier,
            'redirectUri' => $this->redirectUri,
            'codeChallenge' => $this->codeChallenge,
            'scope' => $this->scope,
            'resource' => $this->resource,
            'backendUserIdentifier' => $this->backendUserIdentifier,
            'expires' => $this->expires,
        ];
    }

    public function getClientIdentifier(): string
    {
        return $this->clientIdentifier;
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function getCodeChallenge(): string
    {
        return $this->codeChallenge;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getResource(): string
    {
        return $this->resource;
    }

    public function getBackendUserIdentifier(): int
    {
        return $this->backendUserIdentifier;
    }

    public function isValidAt(int $timestamp): bool
    {
        return $this->backendUserIdentifier > 0 && $this->expires > $timestamp;
    }
}
