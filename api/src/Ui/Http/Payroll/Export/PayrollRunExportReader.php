<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Export;

use App\Organization\Domain\TenantContextInterface;
use App\Payroll\Application\Query\ExportPayrollRun\ExportPayrollRunQuery;
use App\Payroll\Application\Query\ExportPayrollRun\PayrollRunExportView;
use App\Payroll\Domain\Exception\PayrollRunNotFoundException;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Ui\Http\Shared\Csv\Csv;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Answers an export endpoint with a CSV attachment, bypassing the serializer (API Platform passes a
 * Response through), as GET /expenses/{id}/document does.
 */
final readonly class PayrollRunExportReader
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /**
     * @param array<string, mixed>                       $uriVariables
     * @param \Closure(PayrollRunExportView): string     $content
     *
     * @throws PayrollRunNotFoundException
     */
    public function respond(array $uriVariables, string $part, \Closure $content): Response
    {
        $runId = $uriVariables['id'];
        \assert(\is_string($runId));

        /** @var PayrollRunExportView|null $run */
        $run = $this->queryBus->ask(new ExportPayrollRunQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $runId,
        ));
        $run ??= throw PayrollRunNotFoundException::forId($runId);

        $response = new Response($content($run), Response::HTTP_OK, [
            'Content-Type' => Csv::MEDIA_TYPE,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, PayrollRunCsv::fileName($run, $part)),
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();

        return $response;
    }
}
