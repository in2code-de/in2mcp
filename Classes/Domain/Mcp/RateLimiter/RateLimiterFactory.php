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

    public function __construct(protected readonly CachingFrameworkStorage $storage)
    {
    }

    public function create(ServerRequestInterface $request): LimiterInterface
    {
        $factory = new SymfonyRateLimiterFactory($this->getConfiguration(), $this->storage);
        return $factory->create($this->getRemoteAddress($request));
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
}
