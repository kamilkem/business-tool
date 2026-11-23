<?php

declare(strict_types=1);

namespace App\TimeTracking\Application\Job\StartLongShiftReports;

use App\Shared\Application\Job\JobInterface;

/** The hourly fan-out (ADR 0025 §4, #33): queues one ReportLongShiftsJob per organization that may have one. */
final readonly class StartLongShiftReportsJob implements JobInterface
{
}
