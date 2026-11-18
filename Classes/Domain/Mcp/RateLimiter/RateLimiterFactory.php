<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\RateLimiter;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory as SymfonyRateLimiterFactory;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\RateLimiter\Storage\CachingFrameworkStorage;

/**
 * Limits failing authentications per remote address. A successful authentication resets the limit, so only
 * failing requests are counted.
 *
 * An IPv6 address is counted per /64 network: even a home connection gets at least one of them, so counting single
 * addresses would hand out a fresh limit for every request.
 */
class RateLimiterFactory
{
    public const LIMITER_ID = 'in2mcp';

    protected const CONFIGURATION = [
        'id' => self::LIMITER_ID,
        'policy' => 'sliding_window',
        'limit' => 20,
        'interval' => '15 minutes',
    ];

    private const IPV6_NETWORK_BYTES = 8;
    private const IPV6_NETWORK_SUFFIX = '/64';
    private const IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    public function __construct(protected readonly CachingFrameworkStorage $storage)
    {
    }

    public function create(ServerRequestInterface $request): LimiterInterface
    {
        $factory = new SymfonyRateLimiterFactory($this->getConfiguration(), $this->storage);
        return $factory->create($this->getLimitedAddress($this->getRemoteAddress($request)));
    }

    /**
     * Can be overwritten with $GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter']['in2mcp']
     */
    protected function getConfiguration(): array
    {
        $overrides = $GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter'][self::LIMITER_ID] ?? [];
        if (is_array($overrides) === false) {
            return self::CONFIGURATION;
        }
        return array_replace(self::CONFIGURATION, $overrides);
    }

    protected function getRemoteAddress(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if (($normalizedParams instanceof NormalizedParams) === false) {
            $normalizedParams = NormalizedParams::createFromRequest($request);
        }
        return $normalizedParams->getRemoteAddress();
    }

    protected function getLimitedAddress(string $remoteAddress): string
    {
        $packedAddress = filter_var($remoteAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? (string)inet_pton($remoteAddress)
            : '';

        $limitedAddress = $remoteAddress;
        if ($packedAddress !== '' && str_starts_with($packedAddress, self::IPV4_MAPPED_PREFIX)) {
            // A dual stack server sees IPv4 clients as ::ffff:a.b.c.d - as a /64 they would all share one limit
            $limitedAddress = (string)inet_ntop(substr($packedAddress, strlen(self::IPV4_MAPPED_PREFIX)));
        } elseif ($packedAddress !== '') {
            $network = str_pad(substr($packedAddress, 0, self::IPV6_NETWORK_BYTES), strlen($packedAddress), "\0");
            $limitedAddress = inet_ntop($network) . self::IPV6_NETWORK_SUFFIX;
        }
        return $limitedAddress;
    }
}
