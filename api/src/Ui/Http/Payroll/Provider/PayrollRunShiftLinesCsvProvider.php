<?php

declare(strict_types=1);

namespace Ui\Http\Payroll\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\HttpFoundation\Response;
use Ui\Http\Payroll\Export\PayrollRunCsv;
use Ui\Http\Payroll\Export\PayrollRunExportReader;

/** @implements ProviderInterface<Response> */
final readonly class PayrollRunShiftLinesCsvProvider implements ProviderInterface
{
    public function __construct(
        private PayrollRunExportReader $reader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        return $this->reader->respond($uriVariables, 'shifts', PayrollRunCsv::shiftLines(...));
    }
}
