<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123014319 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seller keys of purchase invoices and the suggested-category mark (#55), keys filled in for stored invoices.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_invoice ADD seller_tax_id_key VARCHAR(32) DEFAULT NULL');
        $this->addSql("ALTER TABLE purchase_invoice ADD seller_name_key VARCHAR(512) DEFAULT '' NOT NULL");
        $this->addSql('ALTER TABLE purchase_invoice ADD category_suggested BOOLEAN DEFAULT false NOT NULL');
        // As App\Expenses\Domain\Model\SellerKey computes them; the stored texts are already trimmed.
        $this->addSql("UPDATE purchase_invoice SET seller_tax_id_key = NULLIF(UPPER(REPLACE(REPLACE(seller_tax_id, ' ', ''), '-', '')), ''), seller_name_key = LOWER(seller_name)");
        $this->addSql('ALTER TABLE purchase_invoice ALTER seller_name_key DROP DEFAULT');
        $this->addSql('CREATE INDEX purchase_invoice_organization_seller_tax_id_key ON purchase_invoice (organization_id, seller_tax_id_key)');
        $this->addSql('CREATE INDEX purchase_invoice_organization_seller_name_key ON purchase_invoice (organization_id, seller_name_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX purchase_invoice_organization_seller_tax_id_key');
        $this->addSql('DROP INDEX purchase_invoice_organization_seller_name_key');
        $this->addSql('ALTER TABLE purchase_invoice DROP seller_tax_id_key');
        $this->addSql('ALTER TABLE purchase_invoice DROP seller_name_key');
        $this->addSql('ALTER TABLE purchase_invoice DROP category_suggested');
    }
}
