<?php

declare(strict_types=1);

namespace In2code\In2mcp\Exception;

use Exception;

/**
 * An OAuth error as RFC 6749 describes it: "error" is one of the codes of the specification, the message is the
 * "error_description" and written for the client.
 */
class OAuthException extends Exception
{
    public const INVALID_REQUEST = 'invalid_request';
    public const INVALID_CLIENT = 'invalid_client';
    public const INVALID_GRANT = 'invalid_grant';
    public const INVALID_SCOPE = 'invalid_scope';
    public const INVALID_TARGET = 'invalid_target';
    public const INVALID_REDIRECT_URI = 'invalid_redirect_uri';
    public const INVALID_CLIENT_METADATA = 'invalid_client_metadata';
    public const UNSUPPORTED_GRANT_TYPE = 'unsupported_grant_type';
    public const UNSUPPORTED_RESPONSE_TYPE = 'unsupported_response_type';
    public const ACCESS_DENIED = 'access_denied';
    public const SERVER_ERROR = 'server_error';

    public function __construct(private readonly string $error, string $description, int $code)
    {
        parent::__construct($description, $code);
    }

    public function getError(): string
    {
        return $this->error;
    }

    /**
     * @return array{error: string, error_description: string}
     */
    public function toResponse(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
