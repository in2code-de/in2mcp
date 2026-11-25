<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Record;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Model\TranslationMode;
use In2code\In2mcp\Domain\Repository\PageRepository;
use In2code\In2mcp\Domain\Repository\RecordRepository;
use In2code\In2mcp\Domain\Service\DataHandlerService;
use In2code\In2mcp\Domain\Service\TcaService;
use In2code\In2mcp\Exception\DataHandlerException;
use In2code\In2mcp\Exception\TableNotAccessibleException;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

/**
 * Translating is a command of the DataHandler, not a copy with a changed language field: it connects the
 * translation to its original, so the backend and the frontend treat the two as one record in two languages.
 */
class LocalizeRecordTool extends AbstractTool
{
    protected const DESTRUCTIVE = false;

    public function __construct(
        private readonly DataHandlerService $dataHandlerService,
        private readonly RecordRepository $recordRepository,
        private readonly TcaService $tcaService,
    ) {
    }

    public function getName(): string
    {
        return 'localize_record';
    }

    public function getDescription(): string
    {
        return 'Creates the translation of a page, a content element or any other translatable record in a'
            . ' language, the way the "Translate" button of the backend does. The translation starts as a copy'
            . ' of the original; change its fields afterwards with "update_record" or "update_page". Read'
            . ' existing translations with the "languageId" of "get_page" and "find_records". With "mode"'
            . ' "free" a record that is no page becomes a copy in the language without a connection to its'
            . ' original, like the "Copy" button of the backend - use it for a page whose "translationMode" in'
            . ' "get_page" is "free".';
    }

    public function getParameters(): array
    {
        return [
            'table' => [
                'type' => 'string',
                'description' => 'Table of the record, for example "pages" or "tt_content"',
                'required' => true,
            ],
            'uid' => [
                'type' => 'integer',
                'description' => 'Uid of the record in the default language',
                'required' => true,
            ],
            'languageId' => [
                'type' => 'integer',
                'description' => 'Language the record is translated into, as configured in the site',
                'required' => true,
            ],
            'mode' => [
                'type' => 'string',
                'description' => '"connected" keeps the translation connected to its original, "free" makes it an'
                    . ' independent copy in the language (not for pages)',
                'enum' => [TranslationMode::Connected->value, TranslationMode::Free->value],
                'default' => TranslationMode::Connected->value,
            ],
        ];
    }

    /**
     * @throws DataHandlerException
     * @throws Exception
     * @throws TableNotAccessibleException
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function execute(array $arguments): array
    {
        $table = $this->getStringArgument($arguments, 'table');
        $uid = $this->getIntArgument($arguments, 'uid');
        $languageId = $this->getIntArgument($arguments, 'languageId');

        if ($this->tcaService->getLanguageField($table) === null) {
            throw new ToolExecutionException(
                'Records of "' . $table . '" have no language and cannot be translated',
                1756801804
            );
        }

        if ($languageId < 1) {
            throw new ToolExecutionException(
                'The language of a translation has to be greater than 0, because 0 is the default language',
                1756801808
            );
        }

        $mode = $this->getMode($table, $this->getStringArgument($arguments, 'mode'));
        $translatedUid = $this->dataHandlerService->localizeRecord($table, $uid, $languageId, $mode);

        return [
            'localized' => true,
            'table' => $table,
            'uid' => $translatedUid,
            'originalUid' => $uid,
            'languageId' => $languageId,
            'mode' => $mode->value,
            'record' => $this->recordRepository->findByUid($table, $translatedUid),
        ];
    }

    /**
     * @throws ToolExecutionException
     */
    private function getMode(string $table, string $value): TranslationMode
    {
        $mode = TranslationMode::tryFrom($value);
        $isAllowed = in_array($mode, [TranslationMode::Connected, TranslationMode::Free], true)
            && ($mode === TranslationMode::Connected || $table !== PageRepository::TABLE_NAME);
        if ($isAllowed === false) {
            throw new ToolExecutionException(
                'The mode of a translation is "connected" or "free", and a page is always translated connected',
                1764081000
            );
        }
        return $mode;
    }
}
