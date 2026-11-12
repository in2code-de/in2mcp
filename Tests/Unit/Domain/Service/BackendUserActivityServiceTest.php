<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Repository\BackendUserActivityRepository;
use In2code\In2mcp\Domain\Service\BackendUserActivityService;
use In2code\In2mcp\Domain\Service\BackendUserService;
use In2code\In2mcp\Domain\Service\TcaService;
use In2code\In2mcp\Exception\AdministratorRequiredException;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class BackendUserActivityServiceTest extends UnitTestCase
{
    #[Test]
    public function getActiveMfaProvidersReturnsActiveProviderNamesOnly(): void
    {
        $configuration = '{"totp":{"secret":"ABC","active":true},"recovery-codes":{"codes":["x"],"active":false}}';

        self::assertSame(['totp'], $this->createSubject(true)->getActiveMfaProviders($configuration));
    }

    #[Test]
    public function getActiveMfaProvidersReturnsNothingForEmptyOrBrokenConfiguration(): void
    {
        $subject = $this->createSubject(true);

        self::assertSame([], $subject->getActiveMfaProviders(''));
        self::assertSame([], $subject->getActiveMfaProviders('{broken'));
        self::assertSame([], $subject->getActiveMfaProviders('"text"'));
    }

    #[Test]
    public function getChangedFieldsReturnsFieldNamesWithoutValues(): void
    {
        $historyData = '{"oldRecord":{"header":"Old","hidden":0},"newRecord":{"header":"New","hidden":1}}';

        self::assertSame(['header', 'hidden'], $this->createSubject(true)->getChangedFields($historyData));
    }

    #[Test]
    public function getChangedFieldsReturnsNothingForBrokenHistoryData(): void
    {
        self::assertSame([], $this->createSubject(true)->getChangedFields('not json'));
    }

    #[Test]
    public function getBackendUsersIsRefusedForNonAdministrators(): void
    {
        $this->expectException(AdministratorRequiredException::class);
        $this->createSubject(false)->getBackendUsers();
    }

    #[Test]
    public function getRecentChangesIsRefusedForNonAdministrators(): void
    {
        $this->expectException(AdministratorRequiredException::class);
        $this->createSubject(false)->getRecentChanges(0, '', 10);
    }

    private function createSubject(bool $isAdmin): BackendUserActivityService
    {
        $backendUserService = $this->createStub(BackendUserService::class);
        $backendUserService->method('isAdmin')->willReturn($isAdmin);

        return new BackendUserActivityService(
            $this->createStub(BackendUserActivityRepository::class),
            $backendUserService,
            $this->createStub(TcaService::class)
        );
    }
}
