<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use In2code\In2mcp\Domain\Model\ValidatedUrl;
use In2code\In2mcp\Exception\FileImportException;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\Security\FileNameValidator;
use TYPO3\CMS\Core\SysLog\Action\File as SystemLogFileAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Downloads a file from a public url into a folder of the file storage.
 *
 * The download goes straight into a temporary file and is aborted as soon as it exceeds the configured maximum, so
 * a client cannot fill the disk with a url that answers endlessly. Redirects are followed by hand instead of by
 * the http client, because every hop has to pass the url validation again - otherwise a public url could redirect
 * the server to an internal one and the guard would be worthless. And every hop connects to exactly the address
 * that was validated, so a name server cannot answer with an internal address after the check.
 */
class FileImportService
{
    private const MAXIMUM_REDIRECTS = 3;
    private const CONNECT_TIMEOUT = 5;
    private const TIMEOUT = 30;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly UrlValidationService $urlValidationService,
        private readonly ConfigurationService $configurationService,
        private readonly FileNameValidator $fileNameValidator,
        private readonly BackendUserService $backendUserService,
    ) {
    }

    /**
     * Whether this url may be imported at all. Callers check this before they do any other work, so a refused
     * url is answered with the reason it was refused instead of with a complaint about something else.
     *
     * @throws FileImportException
     */
    public function assertImportable(string $url): void
    {
        if ($this->configurationService->isFileImportActivated() === false) {
            throw new FileImportException(
                'Importing files by url is switched off in the extension configuration of in2mcp',
                1756801200
            );
        }

        if (function_exists('curl_init') === false) {
            throw new FileImportException(
                'Importing files by url needs the PHP extension curl, which is not available on this server',
                1756801260
            );
        }

        try {
            $this->urlValidationService->assertImportable($url);
        } catch (FileImportException $exception) {
            // A client that asks the server to fetch a local address is worth a look, so a refused url is a
            // security notice in the log and not just an error message on the wire.
            $this->log(
                SystemLogErrorClassification::SECURITY_NOTICE,
                'MCP: Refused to import the url "{url}": {reason}',
                ['url' => $url, 'reason' => $exception->getMessage()]
            );
            throw $exception;
        }
    }

    /**
     * @throws FileImportException
     */
    public function importFromUrl(string $url, Folder $folder, string $fileName = ''): File
    {
        $this->assertImportable($url);
        $fileName = $this->getFileName($fileName, $url);
        $temporaryFile = GeneralUtility::tempnam('in2mcp_import_');

        try {
            $this->download($url, $temporaryFile);
            $file = $folder->addFile($temporaryFile, $fileName, DuplicationBehavior::RENAME);
            $this->log(
                SystemLogErrorClassification::MESSAGE,
                'MCP: Imported "{url}" as "{identifier}"',
                ['url' => $url, 'identifier' => $file->getIdentifier()]
            );
            return $file;
        } catch (FileImportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new FileImportException(
                'The file could not be added to "' . $folder->getCombinedIdentifier() . '": '
                . $exception->getMessage(),
                1756801204,
                $exception
            );
        } finally {
            GeneralUtility::unlink_tempfile($temporaryFile);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(int $severity, string $message, array $context): void
    {
        try {
            $this->backendUserService->logFileAction(
                SystemLogFileAction::UPLOAD,
                $severity,
                $message,
                $context
            );
        } catch (Throwable) {
            // Without an authenticated user there is nothing to log against, and a failing log entry must
            // never turn into a failing import.
        }
    }

    /**
     * @throws FileImportException
     */
    private function download(string $url, string $temporaryFile): void
    {
        for ($redirect = 0; $redirect <= self::MAXIMUM_REDIRECTS; $redirect++) {
            $response = $this->request($this->urlValidationService->assertImportable($url), $temporaryFile);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 300 && $statusCode < 400) {
                $url = $this->getRedirectTarget($response, $url);
                continue;
            }

            if ($statusCode !== 200) {
                throw new FileImportException(
                    'The url answered with status ' . $statusCode . ' instead of 200',
                    1756801208
                );
            }

            $this->assertDownloadedFile($temporaryFile);
            return;
        }

        throw new FileImportException(
            'The url redirected more than ' . self::MAXIMUM_REDIRECTS . ' times',
            1756801212
        );
    }

    /**
     * The handler is curl on purpose: only curl connects to a given address (CURLOPT_RESOLVE), the stream handler
     * of Guzzle would resolve the host again and silently ignore every curl option.
     *
     * @throws FileImportException
     */
    private function request(ValidatedUrl $target, string $temporaryFile): ResponseInterface
    {
        $maximumSize = $this->configurationService->getFileImportMaximumSize();
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
            return $this->requestFactory->request($target->getUrl(), 'GET', [
                'handler' => HandlerStack::create(new CurlHandler()),
                'allow_redirects' => false,
                'http_errors' => false,
                'sink' => $temporaryFile,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout' => self::TIMEOUT,
                'curl' => $curlOptions,
            ]);
        } catch (Throwable $exception) {
            throw $isTooLarge
                ? new FileImportException('The file is larger than the allowed ' . $maximumSize . ' bytes', 1756801238)
                : new FileImportException(
                    'The url could not be requested: ' . $exception->getMessage(),
                    1756801216,
                    $exception
                );
        }
    }

    /**
     * @throws FileImportException
     */
    private function getRedirectTarget(ResponseInterface $response, string $currentUrl): string
    {
        $location = $response->getHeaderLine('Location');
        if ($location === '') {
            throw new FileImportException(
                'The url answered with a redirect but without a target',
                1756801220
            );
        }

        if (parse_url($location, PHP_URL_HOST) === null) {
            throw new FileImportException(
                'The url redirects to the relative target "' . $location . '", which is not supported. Use the'
                . ' final url of the file.',
                1756801224
            );
        }

        if ($location === $currentUrl) {
            throw new FileImportException('The url redirects to itself', 1756801228);
        }

        return $location;
    }

    /**
     * @throws FileImportException
     */
    private function assertDownloadedFile(string $temporaryFile): void
    {
        clearstatcache(true, $temporaryFile);
        $size = (int)filesize($temporaryFile);
        $maximumSize = $this->configurationService->getFileImportMaximumSize();

        if ($size === 0) {
            throw new FileImportException('The url answered with an empty file', 1756801240);
        }
        if ($size > $maximumSize) {
            throw new FileImportException(
                'The file is larger than the allowed ' . $maximumSize . ' bytes',
                1756801236
            );
        }
    }

    /**
     * The name decides what the file becomes on disk, so it never comes from the response - only from the
     * client or from the path of the url - and it has to survive the fileDenyPattern of this installation.
     *
     * @throws FileImportException
     */
    private function getFileName(string $fileName, string $url): string
    {
        if ($fileName === '') {
            $fileName = basename((string)parse_url($url, PHP_URL_PATH));
        }

        $fileName = trim(rawurldecode($fileName));
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            throw new FileImportException(
                'No file name could be derived from the url, pass "fileName" explicitly',
                1756801248
            );
        }

        if (str_contains($fileName, '/') || str_contains($fileName, '\\')) {
            throw new FileImportException(
                'The file name must not contain a path, "' . $fileName . '" does',
                1756801252
            );
        }

        if ($this->fileNameValidator->isValid($fileName) === false) {
            throw new FileImportException(
                'The file name "' . $fileName . '" is refused by the fileDenyPattern of this installation',
                1756801256
            );
        }

        return $fileName;
    }
}
