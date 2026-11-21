<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use In2code\In2mcp\Domain\Model\ValidatedUrl;
use In2code\In2mcp\Exception\ResponseTooLargeException;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * The outgoing request of this extension, always to a url that UrlValidationService has checked before. Redirects
 * are never followed here: a caller that follows one has to validate the next url again.
 */
readonly class GuardedRequestService
{
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 30;

    public function __construct(private RequestFactory $requestFactory)
    {
    }

    /**
     * The handler is curl on purpose: only curl connects to a given address (CURLOPT_RESOLVE), the stream handler
     * of Guzzle would resolve the host again and silently ignore every curl option.
     *
     * @param array<string, mixed> $options Further Guzzle options like "sink" or "timeout"
     * @throws ResponseTooLargeException
     * @throws Throwable
     */
    public function request(ValidatedUrl $target, int $maximumSize, array $options = []): ResponseInterface
    {
        $isTooLarge = false;
        $curlOptions = [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_NOPROGRESS => false,
            // Covers the announced size as well as a body without Content-Length that just does not end
            CURLOPT_XFERINFOFUNCTION => static function (
                mixed $handle,
                int $announcedBytes,
                int $receivedBytes
            ) use ($maximumSize, &$isTooLarge): int {
                $isTooLarge = $announcedBytes > $maximumSize || $receivedBytes > $maximumSize;
                return $isTooLarge ? 1 : 0;
            },
        ];
        if ($target->getCurlResolveEntry() !== null) {
            $curlOptions[CURLOPT_RESOLVE] = [$target->getCurlResolveEntry()];
        }

        try {
            return $this->requestFactory->request($target->getUrl(), 'GET', $options + [
                'handler' => HandlerStack::create(new CurlHandler()),
                'allow_redirects' => false,
                'http_errors' => false,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout' => self::TIMEOUT,
                'curl' => $curlOptions,
            ]);
        } catch (Throwable $exception) {
            throw $isTooLarge
                ? new ResponseTooLargeException('The response is larger than ' . $maximumSize . ' bytes', 1763770001)
                : $exception;
        }
    }
}
