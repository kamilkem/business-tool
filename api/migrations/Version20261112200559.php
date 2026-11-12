<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112200559 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expenses: purchase invoices with VAT lines and original documents (#47, ADR 0024)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE purchase_invoice (id UUID NOT NULL, source VARCHAR(16) NOT NULL, external_id VARCHAR(64) DEFAULT NULL, invoice_number VARCHAR(256) NOT NULL, seller_tax_id VARCHAR(32) NOT NULL, seller_name VARCHAR(512) NOT NULL, issue_date DATE NOT NULL, kind VARCHAR(32) NOT NULL, corrected_external_id VARCHAR(64) DEFAULT NULL, currency VARCHAR(3) NOT NULL, tax_currency VARCHAR(3) NOT NULL, net_total BIGINT NOT NULL, vat_total BIGINT NOT NULL, gross_total BIGINT NOT NULL, category_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX purchase_invoice_organization_issue_date ON purchase_invoice (organization_id, issue_date)');
        $this->addSql('CREATE UNIQUE INDEX purchase_invoice_one_per_external_id ON purchase_invoice (organization_id, source, external_id)');
        $this->addSql('CREATE INDEX IDX_8BBFDD3D32C8A3DE ON purchase_invoice (organization_id)');
        $this->addSql('CREATE TABLE purchase_invoice_document (content TEXT NOT NULL, format VARCHAR(32) NOT NULL, sha256 VARCHAR(64) NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, purchase_invoice_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (purchase_invoice_id))');
        $this->addSql('CREATE INDEX IDX_1685500C32C8A3DE ON purchase_invoice_document (organization_id)');
        $this->addSql('CREATE TABLE purchase_invoice_vat_line (rate VARCHAR(8) NOT NULL, net BIGINT NOT NULL, vat BIGINT NOT NULL, vat_in_tax_currency BIGINT DEFAULT NULL, purchase_invoice_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (purchase_invoice_id, rate))');
        $this->addSql('CREATE INDEX IDX_3690A67225BAA637 ON purchase_invoice_vat_line (purchase_invoice_id)');
        $this->addSql('CREATE INDEX IDX_3690A67232C8A3DE ON purchase_invoice_vat_line (organization_id)');
        $this->addSql('ALTER TABLE purchase_invoice ADD CONSTRAINT FK_8BBFDD3D32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchase_invoice_document ADD CONSTRAINT FK_1685500C25BAA637 FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoice (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchase_invoice_document ADD CONSTRAINT FK_1685500C32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchase_invoice_vat_line ADD CONSTRAINT FK_3690A67225BAA637 FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoice (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchase_invoice_vat_line ADD CONSTRAINT FK_3690A67232C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_invoice DROP CONSTRAINT FK_8BBFDD3D32C8A3DE');
        $this->addSql('ALTER TABLE purchase_invoice_document DROP CONSTRAINT FK_1685500C25BAA637');
        $this->addSql('ALTER TABLE purchase_invoice_document DROP CONSTRAINT FK_1685500C32C8A3DE');
        $this->addSql('ALTER TABLE purchase_invoice_vat_line DROP CONSTRAINT FK_3690A67225BAA637');
        $this->addSql('ALTER TABLE purchase_invoice_vat_line DROP CONSTRAINT FK_3690A67232C8A3DE');
        $this->addSql('DROP TABLE purchase_invoice');
        $this->addSql('DROP TABLE purchase_invoice_document');
        $this->addSql('DROP TABLE purchase_invoice_vat_line');
    }
}
