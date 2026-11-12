<?php

declare(strict_types=1);

namespace App\Expenses\Domain\Model;

use App\Expenses\Domain\Exception\InvalidPurchaseInvoiceException;
use App\Organization\Domain\Model\TenantAwareInterface;
use App\Organization\Domain\Model\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The original document of a purchase invoice, kept unchanged next to the invoice's fields (ADR 0024):
 * for an imported invoice, what the importer downloaded (e.g. an e-invoice XML). Opaque to
 * Expenses: the format names what the content is, and the SHA-256 of the content is computed here.
 *
 * At most one per invoice (the invoice's id is the key), in its own table so that the invoice and
 * its lists never load it, and never edited.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_invoice_document')]
class PurchaseInvoiceDocument implements TenantAwareInterface
{
    use TenantAwareTrait;

    public const int MAX_FORMAT_LENGTH = 32;

    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PurchaseInvoice $purchaseInvoice;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    /** What the content is, as the importer names it (e.g. an e-invoice schema's form code). */
    #[ORM\Column(length: self::MAX_FORMAT_LENGTH)]
    private string $format;

    /** SHA-256 of the content, lowercase hex. */
    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    private function __construct(PurchaseInvoice $purchaseInvoice, string $content, string $format, \DateTimeImmutable $receivedAt)
    {
        $this->purchaseInvoice = $purchaseInvoice;
        $this->content = $content;
        $this->format = $format;
        $this->sha256 = hash('sha256', $content);
        $this->receivedAt = $receivedAt;
    }

    /**
     * @param \DateTimeImmutable $receivedAt when the document was downloaded or uploaded
     *
     * @throws InvalidPurchaseInvoiceException when the content or the format is empty, or the format too long
     */
    public static function of(PurchaseInvoice $purchaseInvoice, string $content, string $format, \DateTimeImmutable $receivedAt): self
    {
        if ('' === trim($content)) {
            throw InvalidPurchaseInvoiceException::emptyDocument();
        }
        $format = trim($format);
        if ('' === $format) {
            throw InvalidPurchaseInvoiceException::blank('document format');
        }
        if (mb_strlen($format) > self::MAX_FORMAT_LENGTH) {
            throw InvalidPurchaseInvoiceException::tooLong('document format', self::MAX_FORMAT_LENGTH);
        }

        return new self($purchaseInvoice, $content, $format, $receivedAt);
    }

    public function getPurchaseInvoice(): PurchaseInvoice
    {
        return $this->purchaseInvoice;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }
}
