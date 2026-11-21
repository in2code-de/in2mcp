<?php

declare(strict_types=1);

namespace In2code\In2mcp\Exception;

/**
 * An error of the authorization request that is reported to the client by redirecting the browser back to it.
 * Only possible once client and redirect uri are known to be valid, before that the error is shown to the user.
 */
class OAuthRedirectException extends OAuthException
{
    public function __construct(
        string $error,
        string $description,
        int $code,
        private readonly string $redirectUri,
        private readonly string $state,
    ) {
        parent::__construct($error, $description, $code);
    }

    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    public function getState(): string
    {
        return $this->state;
    }
}
