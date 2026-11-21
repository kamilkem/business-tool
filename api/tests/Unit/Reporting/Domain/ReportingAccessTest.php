<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reporting\Domain;

use App\Organization\Domain\Model\MembershipRole;
use App\Reporting\Domain\Exception\BusinessSummaryForbiddenException;
use App\Reporting\Domain\Model\ReportingAccess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ReportingAccessTest extends TestCase
{
    public function test_it_lets_owners_and_managers_read(): void
    {
        $this->expectNotToPerformAssertions();

        ReportingAccess::assertCanRead(Uuid::v7(), MembershipRole::Owner);
        ReportingAccess::assertCanRead(Uuid::v7(), MembershipRole::Manager);
    }

    public function test_it_refuses_an_employee_with_their_id(): void
    {
        $id = Uuid::v7();

        try {
            ReportingAccess::assertCanRead($id, MembershipRole::Employee);
            self::fail('An employee may read.');
        } catch (BusinessSummaryForbiddenException $exception) {
            self::assertSame('reporting.business_summary_forbidden', $exception->errorCode());
            self::assertSame(['userId' => $id->toRfc4122()], $exception->errorParameters());
        }
    }
}
