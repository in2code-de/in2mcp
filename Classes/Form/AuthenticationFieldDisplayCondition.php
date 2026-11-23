<?php

declare(strict_types=1);

namespace In2code\In2mcp\Form;

use In2code\In2mcp\Domain\Service\ConfigurationService;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * Evaluated with every form instead of in the cached TCA, saving the extension configuration flushes no cache
 */
#[Autoconfigure(public: true)]
readonly class AuthenticationFieldDisplayCondition
{
    public function __construct(private ConfigurationService $configurationService)
    {
    }

    public function isApiKeyAuthenticationActivated(): bool
    {
        return $this->configurationService->isApiKeyAuthenticationActivated();
    }

    public function isOAuthActivated(): bool
    {
        return $this->configurationService->isOAuthActivated();
    }
}
