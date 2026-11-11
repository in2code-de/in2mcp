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

    /**
     * IPv6 prefixes in hex that end with an IPv4 address: IPv4-mapped (::ffff:0:0/96), IPv4-compatible (::/96)
     * and the NAT64 well known prefix (64:ff9b::/96)
     */
    private const IPV4_AT_END_PREFIXES = [
        '00000000000000000000ffff',
        '000000000000000000000000',
        '0064ff9b0000000000000000',
    ];

    /**
     * 6to4 (2002::/16) carries the IPv4 address right after the prefix
     */
    private const SIX_TO_FOUR_PREFIX = '2002';

    private const IPV4_HEX_LENGTH = 8;

    /**
     * Teredo (2001::/32) hides its IPv4 address, NAT64 for local use (64:ff9b:1::/48) leads into a local network
     */
    private const REFUSED_IPV6_PREFIXES = [
        '20010000',
        '0064ff9b0001',
    ];

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
        ) !== false
            && $this->isCarrierGradeNat($address) === false
            && $this->isRefusedTunnelAddress($address) === false;

        $embeddedIpv4Address = $this->getEmbeddedIpv4Address($address);
        if ($isPublic && $embeddedIpv4Address !== null) {
            $isPublic = $this->isPublicAddress($embeddedIpv4Address);
        }

        return $isPublic;
    }

    /**
     * The PHP filter flags take "::ffff:127.0.0.1", "2002:7f00:1::" or "64:ff9b::a9fe:a9fe" for public addresses,
     * while a connection to them ends at the IPv4 address they carry.
     */
    private function getEmbeddedIpv4Address(string $address): ?string
    {
        $hexAddress = $this->getIpv6Hex($address);
        $ipv4Hex = null;
        foreach (self::IPV4_AT_END_PREFIXES as $prefix) {
            if ($hexAddress !== null && str_starts_with($hexAddress, $prefix)) {
                $ipv4Hex = substr($hexAddress, -self::IPV4_HEX_LENGTH);
            }
        }
        if ($hexAddress !== null && str_starts_with($hexAddress, self::SIX_TO_FOUR_PREFIX)) {
            $ipv4Hex = substr($hexAddress, strlen(self::SIX_TO_FOUR_PREFIX), self::IPV4_HEX_LENGTH);
        }

        return $ipv4Hex === null ? null : (string)inet_ntop((string)hex2bin($ipv4Hex));
    }

    private function isRefusedTunnelAddress(string $address): bool
    {
        $hexAddress = $this->getIpv6Hex($address);
        return $hexAddress !== null && array_filter(
            self::REFUSED_IPV6_PREFIXES,
            static fn(string $prefix): bool => str_starts_with($hexAddress, $prefix)
        ) !== [];
    }

    private function getIpv6Hex(string $address): ?string
    {
        $isIpv6 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        return $isIpv6 ? bin2hex((string)inet_pton($address)) : null;
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
