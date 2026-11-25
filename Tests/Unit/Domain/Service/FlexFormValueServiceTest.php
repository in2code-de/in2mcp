<?php

declare(strict_types=1);

namespace In2code\In2mcp\Tests\Unit\Domain\Service;

use In2code\In2mcp\Domain\Service\FlexFormValueService;
use In2code\In2mcp\Domain\Service\TcaService;
use In2code\In2mcp\Exception\ToolExecutionException;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FlexFormValueServiceTest extends UnitTestCase
{
    #[Test]
    public function expandShortFormBuildsTheStructureOfTheDataHandler(): void
    {
        $fields = ['header' => 'Header', 'pi_flexform' => ['sDEF' => ['settings.limit' => 5]]];

        self::assertSame(
            [
                'header' => 'Header',
                'pi_flexform' => ['data' => ['sDEF' => ['lDEF' => ['settings.limit' => ['vDEF' => 5]]]]],
            ],
            $this->createSubject()->expandShortForm('tt_content', $fields)
        );
    }

    #[Test]
    public function expandShortFormLeavesTheCompleteStructureAndXmlUntouched(): void
    {
        $complete = ['pi_flexform' => ['data' => ['sDEF' => ['lDEF' => ['settings.limit' => ['vDEF' => 5]]]]]];
        $xml = ['pi_flexform' => '<T3FlexForms/>'];

        self::assertSame($complete, $this->createSubject()->expandShortForm('tt_content', $complete));
        self::assertSame($xml, $this->createSubject()->expandShortForm('tt_content', $xml));
    }

    #[Test]
    public function expandShortFormRefusesNestedValues(): void
    {
        $this->expectException(ToolExecutionException::class);
        $this->createSubject()->expandShortForm('tt_content', ['pi_flexform' => ['sDEF' => ['a' => ['b' => 1]]]]);
    }

    private function createSubject(): FlexFormValueService
    {
        $tcaService = self::createStub(TcaService::class);
        $tcaService->method('isFlexFormField')->willReturnCallback(
            static fn (string $table, string $fieldName): bool => $fieldName === 'pi_flexform'
        );
        return new FlexFormValueService($tcaService);
    }
}
