<?php

declare(strict_types=1);

namespace App\Expenses\Application\Command\ImportPurchaseInvoice;

use App\Expenses\Application\Command\PurchaseInvoiceItemPrimitives;
use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Expenses\Domain\Exception\PurchaseInvoiceAlreadyImportedException;
use App\Expenses\Domain\Model\PurchaseInvoice;
use App\Expenses\Domain\Model\PurchaseInvoiceDetails;
use App\Expenses\Domain\Model\PurchaseInvoiceDocument;
use App\Expenses\Domain\Model\PurchaseInvoiceKind;
use App\Expenses\Domain\Model\PurchaseInvoiceSource;
use App\Expenses\Domain\Model\PurchaseInvoiceVatAmount;
use App\Expenses\Domain\Repository\PurchaseInvoiceRepositoryInterface;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Time\BusinessDate;
use App\Shared\Domain\Time\Exception\InvalidBusinessDateException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ImportPurchaseInvoiceHandler implements CommandHandlerInterface
{
    public function __construct(
        private PurchaseInvoiceRepositoryInterface $invoices,
        private TenantContextInterface $tenantContext,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PurchaseInvoiceAlreadyImportedException when the source's id is already stored
     * @throws InvalidPurchaseInvoiceException         when the source, kind, date or data break a rule
     */
    public function __invoke(ImportPurchaseInvoiceCommand $command): void
    {
        $organization = $this->tenantContext->getOrganization();
        if ($organization->getId()->toRfc4122() !== $command->organizationId) {
            throw new \LogicException('An invoice is imported only into the organization the importer runs in.');
        }

        $source = PurchaseInvoiceSource::tryFrom($command->source) ?? throw InvalidPurchaseInvoiceException::notImported();
        // Checked first, so that the usual repeat of an import never reaches the unique constraint.
        if (null !== $this->invoices->findByExternalId($source, trim($command->externalId))) {
            throw PurchaseInvoiceAlreadyImportedException::for($source->value, $command->externalId);
        }

        $now = $this->clock->now();
        $invoice = PurchaseInvoice::import(
            Uuid::fromString($command->invoiceId),
            $source,
            $command->externalId,
            self::details($command),
            $organization->getCountryCode(),
            $organization->getCurrency(),
            $now,
        );

        $this->invoices->add($invoice, PurchaseInvoiceDocument::of($invoice, $command->documentContent, $command->documentFormat, $now));
    }

    /** @throws InvalidPurchaseInvoiceException */
    private static function details(ImportPurchaseInvoiceCommand $command): PurchaseInvoiceDetails
    {
        try {
            $issueDate = BusinessDate::fromString($command->issueDate);
        } catch (InvalidBusinessDateException $e) {
            throw InvalidPurchaseInvoiceException::invalid('issue date', $e->getMessage());
        }

        return new PurchaseInvoiceDetails(
            $command->invoiceNumber,
            $command->sellerTaxId,
            $command->sellerName,
            $issueDate,
            PurchaseInvoiceKind::tryFrom($command->kind) ?? throw InvalidPurchaseInvoiceException::invalid('kind', $command->kind),
            $command->currency,
            $command->netTotal,
            $command->vatTotal,
            $command->grossTotal,
            array_map(static fn (array $line): PurchaseInvoiceVatAmount => new PurchaseInvoiceVatAmount($line['rate'], $line['net'], $line['vat'], $line['vatInTaxCurrency']), $command->vatLines),
            $command->correctedExternalId,
            PurchaseInvoiceItemPrimitives::toDetails($command->items),
        );
    }
}
