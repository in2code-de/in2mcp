<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Model;

use In2code\In2mcp\Domain\Model\Base64FileContent;
use In2code\In2mcp\Exception\FileUploadException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class Base64FileContentTest extends UnitTestCase
{
    public static function validContentDataProvider(): array
    {
        return [
            'plain' => [base64_encode('<svg/>'), '<svg/>'],
            'data uri' => ['data:image/svg+xml;base64,' . base64_encode('<svg/>'), '<svg/>'],
            'data uri in upper case' => ['DATA:image/png;BASE64,' . base64_encode('png'), 'png'],
            'line breaks' => [chunk_split(base64_encode(str_repeat('a', 100)), 76, "\r\n"), str_repeat('a', 100)],
            'surrounding whitespace' => ['  ' . base64_encode('ab') . PHP_EOL, 'ab'],
            'binary' => [base64_encode("\x89PNG\x00\xff"), "\x89PNG\x00\xff"],
        ];
    }

    #[Test]
    #[DataProvider('validContentDataProvider')]
    public function decodeReturnsTheOriginalBytes(string $content, string $expectedContent): void
    {
        $subject = new Base64FileContent($content);

        self::assertSame($expectedContent, $subject->decode());
        self::assertSame(strlen($expectedContent), $subject->getDecodedSize());
    }

    public static function invalidContentDataProvider(): array
    {
        return [
            'empty' => [''],
            'only data uri' => ['data:image/png;base64,'],
            'invalid character' => ['YWJj*A=='],
            'base64url alphabet' => ['-_-_'],
            'missing padding' => ['YWI'],
            'padding in the middle' => ['YQ==YQ=='],
            'plain text' => ['this is not base64'],
        ];
    }

    #[Test]
    #[DataProvider('invalidContentDataProvider')]
    public function decodeRefusesInvalidContent(string $content): void
    {
        $this->expectException(FileUploadException::class);
        $this->expectExceptionCode(1795028800);

        (new Base64FileContent($content))->decode();
    }

    #[Test]
    public function decodedSizeIsKnownWithoutDecoding(): void
    {
        $subject = new Base64FileContent(str_repeat('A', 4000));

        self::assertSame(3000, $subject->getDecodedSize());
    }
}
