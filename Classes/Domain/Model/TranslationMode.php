<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Model;

/**
 * How the content of a page is translated, judged like the page module of the backend: connected elements point to
 * their original, free ones do not, and a page with both is mixed.
 */
enum TranslationMode: string
{
    case Connected = 'connected';
    case Free = 'free';
    case Mixed = 'mixed';
    case Empty = 'empty';

    /**
     * @param list<int> $translationParentUids One per content element of the language
     */
    public static function fromTranslationParents(array $translationParentUids): self
    {
        $connectedCount = count(array_filter($translationParentUids, static fn (int $uid): bool => $uid > 0));
        return match (true) {
            $translationParentUids === [] => self::Empty,
            $connectedCount === count($translationParentUids) => self::Connected,
            $connectedCount === 0 => self::Free,
            default => self::Mixed,
        };
    }
}
