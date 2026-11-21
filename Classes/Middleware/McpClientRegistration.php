<?php

declare(strict_types=1);

namespace In2code\In2mcp\Middleware;

use In2code\In2mcp\Domain\Mcp\Authentication\ClientAddressService;
use In2code\In2mcp\Domain\Mcp\RateLimiter\RateLimiterFactory;
use In2code\In2mcp\Domain\Service\ClientRegistrationService;
use In2code\In2mcp\Domain\Service\ConfigurationService;
use In2code\In2mcp\Exception\OAuthException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Dynamic client registration, open to everybody by its nature. Every registration counts against the rate limit
 * of the address, so the table can not be filled from one place.
 */
class McpClientRegistration extends AbstractOAuthEndpoint
{
    private const MAXIMUM_BODY_LENGTH = 65536;

    public function __construct(
        ConfigurationService $configurationService,
        ClientAddressService $clientAddressService,
        LoggerInterface $logger,
        private readonly ClientRegistrationService $clientRegistrationService,
        private readonly RateLimiterFactory $rateLimiterFactory,
    ) {
        parent::__construct($configurationService, $clientAddressService, $logger);
    }

    protected function getPath(): string
    {
        return $this->configurationService->getRegistrationPath();
    }

    protected function getAllowedMethods(): array
    {
        return ['POST'];
    }

    protected function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->rateLimiterFactory->create($request)->consume()->isAccepted() === false) {
            $this->logger->warning('MCP: Too many client registrations, request was rejected');
            return $this->withoutCaching(new JsonResponse(['error' => 'Too many requests'], 429));
        }

        try {
            $registration = $this->clientRegistrationService->register($this->getMetadata($request));
            $this->logger->info('MCP: OAuth client "' . $registration['client_id'] . '" registered');
            return $this->withoutCaching(new JsonResponse($registration, 201));
        } catch (OAuthException $exception) {
            return $this->getErrorResponse($exception);
        } catch (Throwable $exception) {
            $this->logger->error('MCP: Client registration failed: ' . $exception->getMessage());
            return $this->getErrorResponse(
                new OAuthException(OAuthException::SERVER_ERROR, 'Registration failed', 1763740010),
                500
            );
        }
    }

    /**
     * @return array<string, mixed>
     * @throws OAuthException
     */
    private function getMetadata(ServerRequestInterface $request): array
    {
        $stream = $request->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = '';
        while ($stream->eof() === false && strlen($body) <= self::MAXIMUM_BODY_LENGTH) {
            $body .= $stream->read(self::MAXIMUM_BODY_LENGTH + 1 - strlen($body));
        }
        try {
            $metadata = strlen($body) > self::MAXIMUM_BODY_LENGTH
                ? null
                : json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $metadata = null;
        }

        if (is_array($metadata) === false) {
            throw new OAuthException(
                OAuthException::INVALID_CLIENT_METADATA,
                'The request body has to be a json object with the client metadata',
                1763740011
            );
        }
        return $metadata;
    }
}
