<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Model;

/**
 * A url together with the address it was validated against. The download has to connect to exactly this address:
 * resolving the host a second time lets a name server answer with an internal address after the check.
 */
readonly class ValidatedUrl
{
    public function __construct(
        private string $url,
        private string $host,
        private int $port,
        private string $address,
    ) {
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * Entry for CURLOPT_RESOLVE, null for a url that already names an ip address
     */
    public function getCurlResolveEntry(): ?string
    {
        if (filter_var($this->host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $address = filter_var($this->address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $this->address . ']'
            : $this->address;
        return $this->host . ':' . $this->port . ':' . $address;
    }
}
