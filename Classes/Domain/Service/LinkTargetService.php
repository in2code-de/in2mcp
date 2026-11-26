<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Service;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Repository\FileRepository;
use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Exception\UserNotFoundException;
use Throwable;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Schema\Field\LinkFieldType;

/**
 * A page or file the user may not see is reported like a missing one, so the answer reveals no uids
 */
class LinkTargetService
{
    private const TYPE_PAGE = 'page';
    private const TYPE_FILE = 'file';
    private const TYPE_URL = 'url';
    private const CURRENT_PAGE = 'current';

    public function __construct(
        private readonly TcaService $tcaService,
        private readonly LinkService $linkService,
        private readonly TypoLinkCodecService $typoLinkCodecService,
        private readonly PageRepository $pageRepository,
        private readonly FileRepository $fileRepository,
        private readonly BackendUserService $backendUserService,
    ) {
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{links?: array<string, array<string, mixed>>}
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function getLinkReport(string $table, array $fields): array
    {
        $links = [];
        foreach ($fields as $fieldName => $value) {
            $fieldName = (string)$fieldName;
            if (is_string($value) && trim($value) !== ''
                && $this->tcaService->getFieldDefinition($table, $fieldName) instanceof LinkFieldType) {
                $links[$fieldName] = $this->describeLink($value);
            }
        }
        return $links === [] ? [] : ['links' => $links];
    }

    /**
     * @return array<string, mixed>
     * @throws Exception
     * @throws UserNotFoundException
     */
    public function describeLink(string $typoLink): array
    {
        $link = (string)($this->typoLinkCodecService->decode($typoLink)['url'] ?? '');
        try {
            $linkDetails = $this->linkService->resolve($link);
        } catch (Throwable) {
            $linkDetails = [];
        }

        $type = (string)($linkDetails['type'] ?? '');
        $description = match ($type) {
            self::TYPE_PAGE => $this->describePageLink($linkDetails),
            self::TYPE_FILE => $this->describeFileLink($linkDetails),
            '' => ['exists' => false, 'message' => 'This is no valid link, TYPO3 can not resolve it'],
            default => ['message' => $this->getUncheckedMessage($type)],
        };

        return ['link' => $link, 'type' => $type] + $description;
    }

    /**
     * @param array<string, mixed> $linkDetails
     * @return array<string, mixed>
     * @throws Exception
     * @throws UserNotFoundException
     */
    private function describePageLink(array $linkDetails): array
    {
        $pageUid = $linkDetails['pageuid'] ?? 0;
        $description = ['exists' => true, 'message' => 'The link points to the page the record is on'];
        if ($pageUid !== self::CURRENT_PAGE) {
            $page = is_int($pageUid) && $pageUid > 0 ? $this->pageRepository->findByUid($pageUid) : null;
            $description = $page === null
                ? [
                    'exists' => false,
                    'message' => 'There is no page with uid ' . (is_scalar($pageUid) ? (string)$pageUid : '')
                        . ' this backend user may see - the link is broken or points outside of the page mounts',
                ]
                : [
                    'exists' => true,
                    'target' => [
                        'pageUid' => $pageUid,
                        'title' => (string)($page['title'] ?? ''),
                        'hidden' => (bool)($page['hidden'] ?? false),
                    ],
                ];
        }
        return $description;
    }

    /**
     * @param array<string, mixed> $linkDetails
     * @return array<string, mixed>
     * @throws Exception
     * @throws UserNotFoundException
     */
    private function describeFileLink(array $linkDetails): array
    {
        $file = $linkDetails['file'] ?? null;
        $description = [
            'exists' => false,
            'message' => 'There is no file this backend user may use - the link is broken or points outside of the'
                . ' file mounts. Use "search_files" to find a file.',
        ];
        if ($file instanceof File && $this->isFileReadable($file)) {
            $description = [
                'exists' => true,
                'target' => [
                    'fileUid' => $file->getUid(),
                    'identifier' => $file->getIdentifier(),
                    'url' => (string)$file->getPublicUrl(),
                ],
            ];
        }
        return $description;
    }

    /**
     * @throws Exception
     * @throws UserNotFoundException
     */
    private function isFileReadable(File $file): bool
    {
        $fileMounts = $this->backendUserService->hasFullFileAccess()
            ? null
            : $this->backendUserService->getFileMounts();
        return $this->fileRepository->findFileByUid($file->getUid(), $fileMounts) !== null;
    }

    private function getUncheckedMessage(string $type): string
    {
        return $type === self::TYPE_URL
            ? 'An external url is not requested, so it is not checked'
            : 'Links of type "' . $type . '" are not checked';
    }
}
