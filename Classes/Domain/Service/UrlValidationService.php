<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Exception\FileImportException;

/**
 * Guards the one place where this extension makes an outgoing request.
 *
 * A MCP client decides which url the server downloads, so without a guard the server would happily fetch
 * "http://localhost/typo3/install", a cloud metadata endpoint or anything else that is only reachable from
 * inside the network - the classic server side request forgery. Only public http(s) addresses are allowed, and
 * an installation can narrow that down to a list of hosts.
 */
class UrlValidationService
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Carrier grade NAT, which the PHP filter flags do not cover
     */
    private const CARRIER_GRADE_NAT = ['100.64.0.0', '100.127.255.255'];

    public function __construct(private readonly ConfigurationService $configurationService)
    {
    }

    /**
     * @throws FileImportException
     */
    public function assertImportable(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || isset($parts['host']) === false) {
            throw new FileImportException('"' . $url . '" is not a valid url', 1756801100);
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (in_array($scheme, self::ALLOWED_SCHEMES, true) === false) {
            throw new FileImportException(
                'Only http and https urls can be imported, "' . $scheme . '" is not allowed',
                1756801104
            );
        }

        $host = (string)$parts['host'];
        $this->assertAllowedHost($host);
        $this->assertPublicHost($host);
    }

    /**
     * An installation may restrict the import to a list of hosts. An empty list allows every public host.
     *
     * @throws FileImportException
     */
    private function assertAllowedHost(string $host): void
    {
        $allowedHosts = $this->configurationService->getFileImportAllowedHosts();
        if ($allowedHosts === []) {
            return;
        }

        if (in_array(strtolower($host), $allowedHosts, true) === false) {
            throw new FileImportException(
                'The host "' . $host . '" is not in the list of hosts this installation imports from',
                1756801108
            );
        }
    }

    /**
     * Every address a host resolves to has to be public. A host with one public and one private address is
     * refused as a whole, because which one is used at connection time is not in the hands of this code.
     *
     * @throws FileImportException
     */
    private function assertPublicHost(string $host): void
    {
        $addresses = $this->resolve($host);
        if ($addresses === []) {
            throw new FileImportException('The host "' . $host . '" could not be resolved', 1756801112);
        }

        foreach ($addresses as $address) {
            if ($this->isPublicAddress($address) === false) {
                throw new FileImportException(
                    'The host "' . $host . '" resolves to the local or private address ' . $address
                    . ', which this server does not download from',
                    1756801116
                );
            }
        }
    }

    /**
     * @return string[]
     */
    private function resolve(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = gethostbynamel($host);
        $addresses = $addresses === false ? [] : $addresses;

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $addresses[] = (string)$record['ipv6'];
            }
        }

        return $addresses;
    }

    private function isPublicAddress(string $address): bool
    {
        $isPublic = filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;

        if ($isPublic === false) {
            return false;
        }

        return $this->isCarrierGradeNat($address) === false;
    }

    private function isCarrierGradeNat(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $value = ip2long($address);
        return $value >= ip2long(self::CARRIER_GRADE_NAT[0]) && $value <= ip2long(self::CARRIER_GRADE_NAT[1]);
    }
}
