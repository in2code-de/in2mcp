<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Authentication;

use In2code\In2mcp\Domain\Service\ConfigurationService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The address of the MCP client, as the rate limiter and the address check both have to see it. Behind a proxy it
 * is the forwarded address, but only when the proxy is configured in [SYS][reverseProxyIP].
 */
readonly class ClientAddressService
{
    private const NORMALIZED_PARAMS_ATTRIBUTE = 'normalizedParams';
    private const EVERY_ADDRESS = '*';

    public function __construct(private ConfigurationService $configurationService)
    {
    }

    public function getClientAddress(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute(self::NORMALIZED_PARAMS_ATTRIBUTE);
        if (($normalizedParams instanceof NormalizedParams) === false) {
            $normalizedParams = NormalizedParams::createFromRequest($request);
        }
        return $normalizedParams->getRemoteAddress();
    }

    /**
     * "*" and an empty list allow every address. A request without a known address is refused when there is a list.
     */
    public function isAllowedClient(ServerRequestInterface $request): bool
    {
        $allowedAddresses = $this->configurationService->getMcpServerAllowedIps();
        $isUnrestricted = $allowedAddresses === [] || in_array(self::EVERY_ADDRESS, $allowedAddresses, true);
        $clientAddress = $isUnrestricted ? '' : $this->getClientAddress($request);

        return $isUnrestricted
            || ($clientAddress !== '' && GeneralUtility::cmpIP($clientAddress, implode(',', $allowedAddresses)));
    }
}
