<?php

declare(strict_types=1);

namespace Ui\Http\TimeTracking;

use App\TimeTracking\Domain\Model\ShiftChangeRequestStatus;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of GET /shift-change-requests. ShiftChangeRequestCollectionProvider builds it from
 * the query parameters and validates it, so an unknown status is a 422 with violations.
 */
final readonly class ListShiftChangeRequestsInput
{
    public function __construct(
        /** Only requests in this status. */
        #[Assert\Choice(callback: [self::class, 'statuses'])]
        public ?string $status = null,
    ) {
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return array_map(static fn (ShiftChangeRequestStatus $status): string => $status->value, ShiftChangeRequestStatus::cases());
    }
}
