<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Middleware;

use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;

abstract class AbstractMiddlewareTestCase extends AbstractFunctionalTestCase
{
    protected const CLIENT_ADDRESS = '192.0.2.10';
    protected const NOT_HANDLED_STATUS = 418;

    private const HOST = 'typo3-testing.local';

    protected string $clientAddress = self::CLIENT_ADDRESS;

    /**
     * @param array<string, string> $headers
     */
    protected function createBackendRequest(
        string $url,
        string $method,
        ?StreamInterface $body,
        array $headers
    ): ServerRequestInterface {
        $request = new ServerRequest(
            $url,
            $method,
            $body,
            $headers,
            ['HTTP_HOST' => self::HOST, 'REMOTE_ADDR' => $this->clientAddress, 'HTTPS' => 'on']
        );
        return $request
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    // The backend behind the middleware answers with a status no middleware uses, so passing on is visible
    protected function processWith(MiddlewareInterface $middleware, ServerRequestInterface $request): ResponseInterface
    {
        $backend = new class(self::NOT_HANDLED_STATUS) implements RequestHandlerInterface {
            public function __construct(private readonly int $status)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(null, $this->status);
            }
        };
        return $middleware->process($request, $backend);
    }
}
