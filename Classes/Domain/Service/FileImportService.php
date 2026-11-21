<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use In2code\In2mcp\Domain\Model\ValidatedUrl;
use In2code\In2mcp\Exception\FileImportException;
use In2code\In2mcp\Exception\ResponseTooLargeException;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
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

    public function __construct(
        private readonly GuardedRequestService $guardedRequestService,
        private readonly UrlValidationService $urlValidationService,
        private readonly ConfigurationService $configurationService,
        private readonly FileStorageService $fileStorageService,
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
            $this->fileStorageService->log(
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
        $fileName = $this->fileStorageService->getValidFileName($this->getFileName($fileName, $url));
        $temporaryFile = GeneralUtility::tempnam('in2mcp_import_');

        try {
            $this->download($url, $temporaryFile);
            return $this->fileStorageService->addFile($temporaryFile, $folder, $fileName, $url);
        } finally {
            GeneralUtility::unlink_tempfile($temporaryFile);
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

            return;
        }

        throw new FileImportException(
            'The url redirected more than ' . self::MAXIMUM_REDIRECTS . ' times',
            1756801212
        );
    }

    /**
     * @throws FileImportException
     */
    private function request(ValidatedUrl $target, string $temporaryFile): ResponseInterface
    {
        $maximumSize = $this->configurationService->getFileImportMaximumSize();
        try {
            return $this->guardedRequestService->request($target, $maximumSize, ['sink' => $temporaryFile]);
        } catch (ResponseTooLargeException) {
            throw new FileImportException('The file is larger than the allowed ' . $maximumSize . ' bytes', 1756801238);
        } catch (Throwable $exception) {
            throw new FileImportException(
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

    private function getFileName(string $fileName, string $url): string
    {
        if ($fileName === '') {
            $fileName = basename((string)parse_url($url, PHP_URL_PATH));
        }
        return rawurldecode($fileName);
    }
}
