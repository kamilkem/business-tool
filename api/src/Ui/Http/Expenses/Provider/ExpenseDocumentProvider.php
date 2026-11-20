<?php

declare(strict_types=1);

namespace Ui\Http\Expenses\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Expenses\Application\Query\GetPurchaseInvoiceDocument\GetPurchaseInvoiceDocumentQuery;
use App\Expenses\Application\Query\GetPurchaseInvoiceDocument\PurchaseInvoiceDocumentView;
use App\Expenses\Domain\Exception\PurchaseInvoiceDocumentNotFoundException;
use App\Organization\Domain\TenantContextInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Ui\Http\Shared\Security\CurrentUser;

/**
 * Serves an expense's original document from the local copy as it was received, bypassing the
 * serializer (API Platform passes a Response through). Expenses does not know document formats
 * (ADR 0024), so the media type comes from the content: XML when it starts with "<".
 *
 * @implements ProviderInterface<Response>
 */
final readonly class ExpenseDocumentProvider implements ProviderInterface
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CurrentUser $currentUser,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $invoiceId = $uriVariables['id'];
        \assert(\is_string($invoiceId));

        /** @var PurchaseInvoiceDocumentView|null $document */
        $document = $this->queryBus->ask(new GetPurchaseInvoiceDocumentQuery(
            $this->tenantContext->getOrganization()->getId()->toRfc4122(),
            $this->currentUser->id(),
            $invoiceId,
        ));
        $document ??= throw PurchaseInvoiceDocumentNotFoundException::forInvoice($invoiceId);

        $isXml = str_starts_with(ltrim($document->content, "\u{FEFF} \t\r\n"), '<');
        $response = new Response($document->content, Response::HTTP_OK, [
            'Content-Type' => $isXml ? 'application/xml' : 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $document->invoiceId.($isXml ? '.xml' : '')),
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setEtag($document->sha256);
        $response->setPrivate();

        return $response;
    }
}
