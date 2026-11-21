<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Model;

readonly class OAuthGrant
{
    public function __construct(
        private int $uid,
        private int $backendUserIdentifier,
        private string $clientIdentifier,
        private string $clientName,
        private string $scope,
        private string $resource,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int)($row['uid'] ?? 0),
            (int)($row['be_user'] ?? 0),
            (string)($row['client_id'] ?? ''),
            (string)($row['client_name'] ?? ''),
            (string)($row['scope'] ?? ''),
            (string)($row['resource'] ?? '')
        );
    }

    public function getUid(): int
    {
        return $this->uid;
    }

    public function getBackendUserIdentifier(): int
    {
        return $this->backendUserIdentifier;
    }

    public function getClientIdentifier(): string
    {
        return $this->clientIdentifier;
    }

    public function getClientName(): string
    {
        return $this->clientName;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getResource(): string
    {
        return $this->resource;
    }

    public function isReadOnly(): bool
    {
        return AccessScope::isReadOnly(AccessScope::fromScopeString($this->scope));
    }
}
