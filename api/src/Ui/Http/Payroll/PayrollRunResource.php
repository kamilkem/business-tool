<?php

declare(strict_types=1);

namespace Ui\Http\Payroll;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Payroll\Application\Query\GetPayrollRun\PaycheckView;
use App\Payroll\Application\Query\GetPayrollRun\PayrollRunView;
use App\Payroll\Domain\Model\PayrollRunStatus;
use App\Payroll\Domain\Model\RoundingDirection;
use App\Payroll\Domain\Model\RoundingUnit;
use Symfony\Component\Routing\Requirement\Requirement;
use Ui\Http\Payroll\Processor\AddPaycheckAdjustmentProcessor;
use Ui\Http\Payroll\Processor\ApprovePayrollRunProcessor;
use Ui\Http\Payroll\Processor\DeletePayrollRunProcessor;
use Ui\Http\Payroll\Processor\GeneratePayrollRunProcessor;
use Ui\Http\Payroll\Processor\MarkPaycheckPaidProcessor;
use Ui\Http\Payroll\Processor\RegeneratePayrollRunProcessor;
use Ui\Http\Payroll\Processor\RemovePaycheckAdjustmentProcessor;
use Ui\Http\Payroll\Processor\ReopenPayrollRunProcessor;
use Ui\Http\Payroll\Processor\UndoPaycheckPaidProcessor;
use Ui\Http\Payroll\Processor\UpdatePaycheckAdjustmentProcessor;
use Ui\Http\Payroll\Provider\PayrollRunProvider;
use Ui\Http\Payroll\Provider\UpdatePaycheckAdjustmentInputProvider;

/**
 * The pay of one calendar month in the organization selected by the X-Organization-Id header
 * (ADR 0027 §4–10): generated as a draft from the month's ended shifts and the rates in effect, one
 * paycheck per member with lines per shift and adjustments, regenerated until it is right, then
 * approved by an owner, which locks the month's shifts, and its paychecks marked paid. Owners and
 * managers prepare drafts; only owners approve, reopen and mark paid; employees read their own
 * paycheck of approved runs.
 */
#[ApiResource(
    shortName: 'PayrollRun',
    operations: [
        new Get(
            uriTemplate: '/payroll-runs/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'One payroll run with its paychecks.',
                description: 'Owners and managers see any run with every paycheck. An employee sees an approved run with their own paycheck only; a draft, or a run without a paycheck of theirs, is a 404 for them, as is a run that is not the selected organization\'s.',
                responses: ['404' => new Response('The run is not the selected organization\'s, or not visible to the signed-in user.')],
            ),
            provider: PayrollRunProvider::class,
        ),
        new Post(
            uriTemplate: '/payroll-runs',
            openapi: new Operation(
                summary: 'Generates a draft payroll run of a month.',
                description: 'Owners and managers only (403 otherwise). One line per ended shift of the month (a shift belongs to the business date it started on), one paycheck per member with shifts, deactivated members included; the rate is the member\'s day rate for the date, else their hourly rate in effect, in the organization\'s currency (none otherwise: the line has no amount and the run cannot be approved). Each shift\'s length is rounded as asked. One run per month (409). A month after the current one is a 422; the current month\'s draft is a preview. A month with no ended shift is a 409.',
                responses: [
                    '201' => new Response('The new draft.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '409' => new Response('The month has a run, or nothing to pay.'),
                    '422' => new Response('A field is missing or invalid, or the month has not started.'),
                ],
            ),
            input: GeneratePayrollRunInput::class,
            read: false,
            processor: GeneratePayrollRunProcessor::class,
        ),
        new Post(
            uriTemplate: '/payroll-runs/{id}/regenerate',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Rebuilds a draft from the current shifts and rates.',
                description: 'Owners and managers only (403 otherwise). Keeps the adjustments and, unless new rounding is given, the rounding; the currency becomes the organization\'s. An approved run is a 409 (reopen it first), and so is a result with nothing to pay.',
                responses: [
                    '200' => new Response('The regenerated draft.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The run is not the selected organization\'s.'),
                    '409' => new Response('The run is approved, or nothing would be left to pay.'),
                    '422' => new Response('The rounding is invalid or incomplete.'),
                ],
            ),
            input: RegeneratePayrollRunInput::class,
            read: false,
            processor: RegeneratePayrollRunProcessor::class,
        ),
        new Post(
            uriTemplate: '/payroll-runs/{id}/approve',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Approves a draft, which locks the month\'s shifts.',
                description: 'Owners only (403 otherwise). A 409 that names the reason when: the run is approved already; the month has not ended; a shift of the month is open; a shift change request about the month is pending; a line has no rate; a paycheck total is negative; the run has no paychecks; or shifts, rates or the currency changed since it was generated (regenerate and review it again). Once approved, the month\'s shifts, rates and day rates cannot change.',
                responses: [
                    '200' => new Response('The approved run.'),
                    '403' => new Response('The signed-in user is not an owner.'),
                    '404' => new Response('The run is not the selected organization\'s.'),
                    '409' => new Response('The run cannot be approved; the detail says why.'),
                ],
            ),
            input: false,
            read: false,
            processor: ApprovePayrollRunProcessor::class,
        ),
        new Post(
            uriTemplate: '/payroll-runs/{id}/reopen',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Reopens an approved run back to draft, which unlocks the month\'s shifts.',
                description: 'Owners only (403 otherwise). A draft is a 409, and so is a run with a paycheck marked paid: undo the marks first, or fix a mistake with an adjustment in a later month.',
                responses: [
                    '200' => new Response('The draft.'),
                    '403' => new Response('The signed-in user is not an owner.'),
                    '404' => new Response('The run is not the selected organization\'s.'),
                    '409' => new Response('The run is a draft, or a paycheck is marked paid.'),
                ],
            ),
            input: false,
            read: false,
            processor: ReopenPayrollRunProcessor::class,
        ),
        new Post(
            uriTemplate: '/payroll-runs/{id}/paychecks/{paycheckId}/payment',
            uriVariables: ['id', 'paycheckId'],
            requirements: ['id' => Requirement::UUID, 'paycheckId' => Requirement::UUID],
            status: 200,
            openapi: new Operation(
                summary: 'Marks a paycheck of an approved run paid.',
                description: 'Owners only (403 otherwise). The business date it was paid on, today or earlier in the organization\'s time zone (422 otherwise), and the method. A draft run is a 409, and so is a paycheck marked paid already (undo the mark to change it). The run is paid once all its paychecks are; a run with a paid paycheck cannot be reopened.',
                responses: [
                    '200' => new Response('The run with the paycheck marked paid.'),
                    '403' => new Response('The signed-in user is not an owner.'),
                    '404' => new Response('The run or the paycheck is not the selected organization\'s.'),
                    '409' => new Response('The run is a draft, or the paycheck is marked paid already.'),
                    '422' => new Response('A field is missing or invalid, or the date is after today.'),
                ],
            ),
            input: MarkPaycheckPaidInput::class,
            read: false,
            processor: MarkPaycheckPaidProcessor::class,
        ),
        new Delete(
            uriTemplate: '/payroll-runs/{id}/paychecks/{paycheckId}/payment',
            uriVariables: ['id', 'paycheckId'],
            requirements: ['id' => Requirement::UUID, 'paycheckId' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Undoes the paid mark of a paycheck.',
                description: 'Owners only (403 otherwise). A paycheck that is not marked paid is a 409.',
                responses: [
                    '204' => new Response('The paycheck is no longer marked paid.'),
                    '403' => new Response('The signed-in user is not an owner.'),
                    '404' => new Response('The run or the paycheck is not the selected organization\'s.'),
                    '409' => new Response('The paycheck is not marked paid.'),
                ],
            ),
            read: false,
            processor: UndoPaycheckPaidProcessor::class,
        ),
        new Delete(
            uriTemplate: '/payroll-runs/{id}',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Deletes a draft payroll run.',
                description: 'Owners and managers only (403 otherwise). An approved run is a 409 (reopen it first).',
                responses: [
                    '204' => new Response('The draft was deleted.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The run is not the selected organization\'s.'),
                    '409' => new Response('The run is approved.'),
                ],
            ),
            read: false,
            processor: DeletePayrollRunProcessor::class,
        ),
        new Post(
            uriTemplate: '/payroll-runs/{id}/adjustments',
            uriVariables: ['id'],
            requirements: ['id' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Adds a bonus or a deduction to a member\'s paycheck on a draft.',
                description: 'Owners and managers only (403 otherwise), for any member, active or deactivated (404 otherwise); a member with no shifts that month gets a paycheck. Kept when the run is regenerated. An approved run is a 409.',
                responses: [
                    '201' => new Response('The run with the adjustment.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The run is not the selected organization\'s, or the userId is not a member.'),
                    '409' => new Response('The run is approved.'),
                    '422' => new Response('A field is missing or invalid.'),
                ],
            ),
            input: AddPaycheckAdjustmentInput::class,
            read: false,
            processor: AddPaycheckAdjustmentProcessor::class,
        ),
        new Patch(
            uriTemplate: '/payroll-runs/{id}/adjustments/{adjustmentId}',
            uriVariables: ['id', 'adjustmentId'],
            requirements: ['id' => Requirement::UUID, 'adjustmentId' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Corrects an adjustment on a draft.',
                description: 'Owners and managers only (403 otherwise). An approved run is a 409.',
                responses: [
                    '200' => new Response('The run with the corrected adjustment.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The run or the adjustment is not the selected organization\'s.'),
                    '409' => new Response('The run is approved.'),
                    '422' => new Response('A field is invalid.'),
                ],
            ),
            input: UpdatePaycheckAdjustmentInput::class,
            provider: UpdatePaycheckAdjustmentInputProvider::class,
            processor: UpdatePaycheckAdjustmentProcessor::class,
        ),
        new Delete(
            uriTemplate: '/payroll-runs/{id}/adjustments/{adjustmentId}',
            uriVariables: ['id', 'adjustmentId'],
            requirements: ['id' => Requirement::UUID, 'adjustmentId' => Requirement::UUID],
            openapi: new Operation(
                summary: 'Removes an adjustment from a draft.',
                description: 'Owners and managers only (403 otherwise). A paycheck left with no shift lines and no adjustments is removed too. An approved run is a 409.',
                responses: [
                    '204' => new Response('The adjustment was removed.'),
                    '403' => new Response('The signed-in user is not an owner or manager.'),
                    '404' => new Response('The run or the adjustment is not the selected organization\'s.'),
                    '409' => new Response('The run is approved.'),
                ],
            ),
            read: false,
            processor: RemovePaycheckAdjustmentProcessor::class,
        ),
    ],
)]
final readonly class PayrollRunResource
{
    /** @param list<PayrollRunPaycheck> $paychecks */
    public function __construct(
        public string $id,
        /** The calendar month (YYYY-MM) in the organization's time zone. */
        public string $month,
        /** The month's first business date (YYYY-MM-DD). */
        public string $firstDate,
        /** The month's last business date (YYYY-MM-DD). */
        public string $lastDate,
        public PayrollRunStatus $status,
        /** ISO 4217: the organization's when the run was last generated. */
        public string $currency,
        public RoundingUnit $roundingUnit,
        public RoundingDirection $roundingDirection,
        /** Shifts of the month still open when the run was last generated; they are not on any paycheck. */
        public int $openShifts,
        /** Pending shift change requests about the month when the run was last generated. */
        public int $pendingChangeRequests,
        /** Lines of the paychecks shown that have no rate. */
        public int $linesWithoutRate,
        /** The sum of the totals of the paychecks shown, in minor units. */
        public int $total,
        /** How many of the paychecks shown are marked paid. */
        public int $paidPaychecks,
        /** Approved, and every paycheck shown is marked paid (derived, not stored). */
        public bool $paid,
        /** By member id; an employee sees only their own. */
        public array $paychecks,
        public string $createdBy,
        public \DateTimeImmutable $createdAt,
        /** Who generated or regenerated the run last. */
        public string $generatedBy,
        public \DateTimeImmutable $generatedAt,
        /** The owner who approved it; null for a draft. */
        public ?string $approvedBy,
        public ?\DateTimeImmutable $approvedAt,
    ) {
    }

    public static function fromView(PayrollRunView $view): self
    {
        return new self(
            id: $view->id,
            month: $view->month,
            firstDate: $view->firstDate,
            lastDate: $view->lastDate,
            status: PayrollRunStatus::from($view->status),
            currency: $view->currency,
            roundingUnit: RoundingUnit::from($view->roundingUnit),
            roundingDirection: RoundingDirection::from($view->roundingDirection),
            openShifts: $view->openShifts,
            pendingChangeRequests: $view->pendingChangeRequests,
            linesWithoutRate: $view->linesWithoutRate,
            total: $view->total,
            paidPaychecks: $view->paidPaychecks,
            paid: $view->paid,
            paychecks: array_map(static fn (PaycheckView $paycheck): PayrollRunPaycheck => PayrollRunPaycheck::fromView($paycheck), $view->paychecks),
            createdBy: $view->createdBy,
            createdAt: $view->createdAt,
            generatedBy: $view->generatedBy,
            generatedAt: $view->generatedAt,
            approvedBy: $view->approvedBy,
            approvedAt: $view->approvedAt,
        );
    }
}
