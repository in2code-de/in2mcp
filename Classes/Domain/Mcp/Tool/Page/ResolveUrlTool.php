<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Mcp\Tool\Page;

use Doctrine\DBAL\Exception;
use In2code\In2mcp\Domain\Mcp\Tool\AbstractTool;
use In2code\In2mcp\Domain\Service\UrlResolutionService;
use In2code\In2mcp\Exception\ToolExecutionException;
use In2code\In2mcp\Exception\UserNotFoundException;

class ResolveUrlTool extends AbstractTool
{
    protected const READ_ONLY = true;

    public function __construct(
        private readonly UrlResolutionService $urlResolutionService,
    ) {
    }

    public function getName(): string
    {
        return 'resolve_url';
    }

    public function getDescription(): string
    {
        return 'Finds out what a frontend url of this installation leads to, without requesting it: the site and'
            . ' language of the url, a redirect of EXT:redirects that applies to it, and the page behind its slug'
            . ' with "pageUid" (the uid in the default language) and "recordUid" (the uid of the translation for a'
            . ' language above 0). "status" sums it up: "redirect" (a redirect is applied before any page is'
            . ' looked at), "page" (the page is delivered), "unavailable" (there is a page, but'
            . ' "reasonsForMissingOutput" explains why a visitor gets none - hidden, start or end time, frontend'
            . ' user groups, a page type without output or a disabled language) or "not_found". For a missing page'
            . ' in a language above 0 it says whether the slug belongs to a page of the default language. Use it'
            . ' to find the page of a url or to find out why a url ends in a 404. Only pages the backend user may'
            . ' see are reported, every other page counts as missing.';
    }

    public function getParameters(): array
    {
        return [
            'url' => [
                'type' => 'string',
                'description' => 'Absolute url including host, for example "https://www.example.org/en/about/"',
                'required' => true,
            ],
        ];
    }

    /**
     * @throws Exception
     * @throws ToolExecutionException
     * @throws UserNotFoundException
     */
    public function execute(array $arguments): array
    {
        return $this->urlResolutionService->resolve($this->getStringArgument($arguments, 'url'));
    }
}
