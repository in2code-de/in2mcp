<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Functional\Domain\Mcp\Tool\File;

use In2code\In2mcp\Exception\ClientFacingException;
use In2code\In2mcp\Tests\Functional\AbstractFunctionalTestCase;
use PHPUnit\Framework\Attributes\Test;

final class FileToolsTest extends AbstractFunctionalTestCase
{
    private const FILE_IN_MOUNT = 1;
    private const FILE_OUTSIDE_OF_MOUNT = 2;
    private const UNUSED_FILE_IN_MOUNT = 3;

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/in2mcp/Tests/Functional/Fixtures/Files/fileadmin/' => 'fileadmin/',
    ];

    protected function setUp(): void
    {
        $this->configurationToUseInTestInstance['EXTENSIONS']['in2mcp']['fileUpload'] = '1';
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../../../Fixtures/Files.csv');
    }

    #[Test]
    public function searchFilesReturnsOnlyFilesOfTheFileMounts(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('search_files');

        self::assertSame(['image.png', 'unused.txt'], $this->getSortedNames($result['files']));
    }

    #[Test]
    public function searchFilesReturnsEveryFileForAnAdministrator(): void
    {
        $this->loginAs(self::ADMIN);

        $result = $this->callTool('search_files');

        self::assertSame(['image.png', 'secret.txt', 'unused.txt'], $this->getSortedNames($result['files']));
    }

    #[Test]
    public function searchFilesFindsFilesWithEmptyMetadata(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('search_files', ['emptyMetadata' => ['title']]);

        self::assertSame(['unused.txt'], $this->getSortedNames($result['files']));
    }

    #[Test]
    public function addFileReferenceConnectsAFileWithAContentElement(): void
    {
        $this->loginAs(self::EDITOR);
        $contentUid = $this->callTool(
            'create_content_element',
            ['pageUid' => 2, 'contentType' => 'textmedia']
        )['contentUid'];

        $result = $this->callTool(
            'add_file_reference',
            ['fileUid' => self::FILE_IN_MOUNT, 'table' => 'tt_content', 'uid' => $contentUid, 'fieldName' => 'assets']
        );

        self::assertTrue($result['added']);
        $reference = $this->callTool(
            'get_record',
            ['table' => 'sys_file_reference', 'uid' => $result['fileReferenceUid']]
        );
        self::assertSame($contentUid, (int)$reference['record']['uid_foreign']);
        self::assertSame('assets', $reference['record']['fieldname']);
        $content = $this->callTool('get_record', ['table' => 'tt_content', 'uid' => $contentUid]);
        self::assertSame(1, $this->countReferences($content['record']['assets']));
    }

    #[Test]
    public function addFileReferenceRefusesAFileOutsideOfTheFileMounts(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool(
            'add_file_reference',
            ['fileUid' => self::FILE_OUTSIDE_OF_MOUNT, 'table' => 'tt_content', 'uid' => 1, 'fieldName' => 'assets']
        );
    }

    #[Test]
    public function addFileReferenceRefusesAFileTypeTheFieldDoesNotAccept(): void
    {
        $this->loginAs(self::EDITOR);
        $contentUid = $this->callTool(
            'create_content_element',
            ['pageUid' => 2, 'contentType' => 'textmedia']
        )['contentUid'];

        $exception = null;
        try {
            $this->callTool(
                'add_file_reference',
                [
                    'fileUid' => self::UNUSED_FILE_IN_MOUNT,
                    'table' => 'tt_content',
                    'uid' => $contentUid,
                    'fieldName' => 'assets',
                ]
            );
        } catch (ClientFacingException $exception) {
        }

        self::assertInstanceOf(ClientFacingException::class, $exception);
        self::assertStringContainsString('"unused.txt"', $exception->getMessage());
        self::assertStringContainsString('png', $exception->getMessage());
        self::assertSame([], $this->callTool('find_records', ['table' => 'sys_file_reference'])['records']);
    }

    #[Test]
    public function addFileReferenceRefusesAFileTypeTheFieldDoesNotAcceptForAnAdministrator(): void
    {
        $this->loginAs(self::ADMIN);

        $this->expectException(ClientFacingException::class);
        $this->callTool(
            'add_file_reference',
            ['fileUid' => self::UNUSED_FILE_IN_MOUNT, 'table' => 'tt_content', 'uid' => 1, 'fieldName' => 'assets']
        );
    }

    #[Test]
    public function addFileReferenceRefusesAFieldThatHoldsNoFiles(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool(
            'add_file_reference',
            ['fileUid' => self::FILE_IN_MOUNT, 'table' => 'tt_content', 'uid' => 1, 'fieldName' => 'header']
        );
    }

    #[Test]
    public function addFileReferenceRefusesFieldsTheDataHandlerDerives(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool(
            'add_file_reference',
            [
                'fileUid' => self::FILE_IN_MOUNT,
                'table' => 'tt_content',
                'uid' => 1,
                'fieldName' => 'assets',
                'fields' => ['uid_foreign' => 3],
            ]
        );
    }

    #[Test]
    public function deleteFileRefusesAFileThatIsStillUsed(): void
    {
        $this->loginAs(self::EDITOR);
        $contentUid = $this->callTool(
            'create_content_element',
            ['pageUid' => 2, 'contentType' => 'textmedia']
        )['contentUid'];
        $this->callTool(
            'add_file_reference',
            ['fileUid' => self::FILE_IN_MOUNT, 'table' => 'tt_content', 'uid' => $contentUid, 'fieldName' => 'assets']
        );

        $this->expectException(ClientFacingException::class);
        $this->callTool('delete_file', ['fileUid' => self::FILE_IN_MOUNT]);
    }

    #[Test]
    public function deleteFileRefusesAFileOutsideOfTheFileMounts(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool('delete_file', ['fileUid' => self::FILE_OUTSIDE_OF_MOUNT]);
    }

    #[Test]
    public function deleteFileRemovesAnUnusedFile(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('delete_file', ['fileUid' => self::UNUSED_FILE_IN_MOUNT]);

        self::assertTrue($result['deleted']);
        self::assertNotContains('unused.txt', $this->getSortedNames($this->callTool('search_files')['files']));
    }

    #[Test]
    public function addFileFromContentWritesTheFileIntoTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool(
            'add_file_from_content',
            ['content' => base64_encode('Hello'), 'fileName' => 'hello.txt', 'targetFolder' => '1:/editor/']
        );

        self::assertTrue($result['imported']);
        self::assertStringEqualsFile($this->instancePath . '/fileadmin/editor/hello.txt', 'Hello');
    }

    #[Test]
    public function addFileFromContentRefusesAFolderOutsideOfTheMount(): void
    {
        $this->loginAs(self::EDITOR);

        $this->expectException(ClientFacingException::class);
        $this->callTool(
            'add_file_from_content',
            ['content' => base64_encode('Hello'), 'fileName' => 'hello.txt', 'targetFolder' => '1:/private/']
        );
    }

    #[Test]
    public function addFileFromContentRefusesTheFallbackStorage(): void
    {
        $this->loginAs(self::ADMIN);

        $this->expectException(ClientFacingException::class);
        $this->callTool(
            'add_file_from_content',
            ['content' => base64_encode('Hello'), 'fileName' => 'hello.txt', 'targetFolder' => '0:/typo3temp/']
        );
    }

    #[Test]
    public function addFileFromContentRefusesAPhpFile(): void
    {
        $this->loginAs(self::ADMIN);

        $this->expectException(ClientFacingException::class);
        $this->callTool(
            'add_file_from_content',
            ['content' => base64_encode('<?php echo 1;'), 'fileName' => 'shell.php', 'targetFolder' => '1:/editor/']
        );
    }

    #[Test]
    public function createFileUploadHandsOutAToken(): void
    {
        $this->loginAs(self::EDITOR);

        $result = $this->callTool('create_file_upload', ['targetFolder' => '1:/editor/']);

        self::assertNotSame('', $result['token']);
        self::assertSame('1:/editor/', $result['folder']);
        self::assertStringEndsWith('/typo3/mcp/upload', $result['uploadUrl']);
    }

    #[Test]
    public function addFileFromUrlIsRefusedWhileTheImportIsSwitchedOff(): void
    {
        $this->loginAs(self::ADMIN);

        $this->expectException(ClientFacingException::class);
        $this->callTool('add_file_from_url', ['url' => 'https://example.org/image.png']);
    }

    /**
     * @param list<array<string, mixed>> $files
     * @return list<string>
     */
    private function getSortedNames(array $files): array
    {
        $names = array_column($files, 'name');
        sort($names);
        return $names;
    }

    private function countReferences(mixed $fieldValue): int
    {
        return is_array($fieldValue) ? count($fieldValue) : (int)$fieldValue;
    }
}
