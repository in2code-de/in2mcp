<?php

declare(strict_types=1);

namespace In2code\In2mcp\Domain\Event\TableAccess;

// Mutable unlike a notification event: listeners change the built-in lists, "deniedTables" is applied afterwards
final class ModifyTableAccessEvent
{
    /**
     * @param list<string> $deniedTables
     * @param array<string, string> $readOnlyTables table => reason given to the client
     */
    public function __construct(private array $deniedTables, private array $readOnlyTables)
    {
    }

    /**
     * @return list<string>
     */
    public function getDeniedTables(): array
    {
        return $this->deniedTables;
    }

    /**
     * @param list<string> $deniedTables
     */
    public function setDeniedTables(array $deniedTables): void
    {
        $this->deniedTables = array_values($deniedTables);
    }

    /**
     * @return array<string, string>
     */
    public function getReadOnlyTables(): array
    {
        return $this->readOnlyTables;
    }

    /**
     * @param array<string, string> $readOnlyTables table => reason given to the client
     */
    public function setReadOnlyTables(array $readOnlyTables): void
    {
        $this->readOnlyTables = $readOnlyTables;
    }
}
