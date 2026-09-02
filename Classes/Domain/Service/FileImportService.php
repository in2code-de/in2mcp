<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

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
 * The download is streamed and aborted as soon as it exceeds the configured maximum, so a client cannot fill
 * the disk with a url that answers endlessly. Redirects are followed by hand instead of by the http client,
 * because every hop has to pass the url validation again - otherwise a public url could redirect the server to
 * an internal one and the guard would be worthless.
 */
class FileImportService
{
    private const MAXIMUM_REDIRECTS = 3;
    private const CHUNK_SIZE = 65536;
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

        $response = $this->fetch($url);
        $fileName = $this->getFileName($fileName, $url);
        $temporaryFile = $this->writeToTemporaryFile($response);

        try {
            $file = $folder->addFile($temporaryFile, $fileName, DuplicationBehavior::RENAME);
            $this->log(
                SystemLogErrorClassification::MESSAGE,
                'MCP: Imported "{url}" as "{identifier}"',
                ['url' => $url, 'identifier' => $file->getIdentifier()]
            );
            return $file;
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
    private function fetch(string $url): ResponseInterface
    {
        for ($redirect = 0; $redirect <= self::MAXIMUM_REDIRECTS; $redirect++) {
            $this->urlValidationService->assertImportable($url);
            $response = $this->request($url);
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

            $this->assertSizeAllowed((int)($response->getHeaderLine('Content-Length') ?: 0));
            return $response;
        }

        throw new FileImportException(
            'The url redirected more than ' . self::MAXIMUM_REDIRECTS . ' times',
            1756801212
        );
    }

    /**
     * @throws FileImportException
     */
    private function request(string $url): ResponseInterface
    {
        try {
            return $this->requestFactory->request($url, 'GET', [
                'allow_redirects' => false,
                'http_errors' => false,
                'stream' => true,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout' => self::TIMEOUT,
            ]);
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

    /**
     * The body is read in chunks so the download can be stopped at the maximum instead of being held in memory
     * completely before anybody looks at its size.
     *
     * @return string Path of the temporary file
     * @throws FileImportException
     */
    private function writeToTemporaryFile(ResponseInterface $response): string
    {
        $temporaryFile = GeneralUtility::tempnam('in2mcp_import_');
        $maximumSize = $this->configurationService->getFileImportMaximumSize();
        $handle = fopen($temporaryFile, 'wb');

        if ($handle === false) {
            throw new FileImportException('A temporary file could not be opened', 1756801232);
        }

        $body = $response->getBody();
        $writtenBytes = 0;

        try {
            while ($body->eof() === false) {
                $chunk = $body->read(self::CHUNK_SIZE);
                if ($chunk === '') {
                    break;
                }

                $writtenBytes += strlen($chunk);
                if ($writtenBytes > $maximumSize) {
                    throw new FileImportException(
                        'The file is larger than the allowed ' . $maximumSize . ' bytes',
                        1756801236
                    );
                }

                fwrite($handle, $chunk);
            }
        } catch (FileImportException $exception) {
            fclose($handle);
            GeneralUtility::unlink_tempfile($temporaryFile);
            throw $exception;
        }

        fclose($handle);

        if ($writtenBytes === 0) {
            GeneralUtility::unlink_tempfile($temporaryFile);
            throw new FileImportException('The url answered with an empty file', 1756801240);
        }

        return $temporaryFile;
    }

    /**
     * @throws FileImportException
     */
    private function assertSizeAllowed(int $announcedSize): void
    {
        $maximumSize = $this->configurationService->getFileImportMaximumSize();
        if ($announcedSize > $maximumSize) {
            throw new FileImportException(
                'The url announces ' . $announcedSize . ' bytes, which is more than the allowed ' . $maximumSize,
                1756801244
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
