<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Model;

readonly class UploadToken
{
    public function __construct(
        private int $userIdentifier,
        private string $folder,
        private string $fileName,
        private int $expires,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int)($data['userIdentifier'] ?? 0),
            (string)($data['folder'] ?? ''),
            (string)($data['fileName'] ?? ''),
            (int)($data['expires'] ?? 0)
        );
    }

    /**
     * @return array{userIdentifier: int, folder: string, fileName: string, expires: int}
     */
    public function toArray(): array
    {
        return [
            'userIdentifier' => $this->userIdentifier,
            'folder' => $this->folder,
            'fileName' => $this->fileName,
            'expires' => $this->expires,
        ];
    }

    public function getUserIdentifier(): int
    {
        return $this->userIdentifier;
    }

    public function getFolder(): string
    {
        return $this->folder;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getExpires(): int
    {
        return $this->expires;
    }

    public function isValidAt(int $timestamp): bool
    {
        return $this->userIdentifier > 0 && $this->expires > $timestamp;
    }
}
