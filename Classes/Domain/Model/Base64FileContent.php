<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Model;

use In2code\In2mcp\Exception\FileUploadException;

readonly class Base64FileContent
{
    private const DATA_URI_PATTERN = '~^data:[^,]*;base64,~i';
    private const WHITESPACE_PATTERN = '~\s+~';
    private const BASE64_PATTERN = '~^[A-Za-z0-9+/]*={0,2}$~';

    private string $encodedContent;

    public function __construct(string $content)
    {
        $content = preg_replace(self::DATA_URI_PATTERN, '', trim($content));
        $this->encodedContent = (string)preg_replace(self::WHITESPACE_PATTERN, '', (string)$content);
    }

    public function getDecodedSize(): int
    {
        $length = strlen($this->encodedContent);
        $padding = $length - strlen(rtrim($this->encodedContent, '='));
        return max(0, intdiv($length * 3, 4) - $padding);
    }

    /**
     * @throws FileUploadException
     */
    public function decode(): string
    {
        $decodedContent = $this->isWellFormed() ? base64_decode($this->encodedContent, true) : false;

        if ($decodedContent === false || $decodedContent === '') {
            throw new FileUploadException(
                'The content is no valid base64 or empty. Pass the bytes of the file base64 encoded, optionally'
                . ' as data uri ("data:image/png;base64,...").',
                1795028800
            );
        }

        return $decodedContent;
    }

    private function isWellFormed(): bool
    {
        return strlen($this->encodedContent) % 4 === 0
            && preg_match(self::BASE64_PATTERN, $this->encodedContent) === 1;
    }
}
