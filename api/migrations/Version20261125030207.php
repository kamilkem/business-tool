<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125030207 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchase invoices keep their items as printed (ADR 0040, #229); invoices imported before have none until re-read (#230)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE purchase_invoice_item (position INT NOT NULL, name VARCHAR(512) NOT NULL, seller_code VARCHAR(64) DEFAULT NULL, gtin VARCHAR(32) DEFAULT NULL, unit VARCHAR(256) DEFAULT NULL, quantity NUMERIC(22, 6) DEFAULT NULL, unit_net_price NUMERIC(22, 8) DEFAULT NULL, unit_gross_price NUMERIC(22, 8) DEFAULT NULL, discount NUMERIC(22, 8) DEFAULT NULL, net_amount BIGINT DEFAULT NULL, gross_amount BIGINT DEFAULT NULL, vat_amount BIGINT DEFAULT NULL, vat_rate VARCHAR(8) DEFAULT NULL, before_correction BOOLEAN NOT NULL, ordinal INT NOT NULL, purchase_invoice_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (purchase_invoice_id, ordinal))');
        $this->addSql('CREATE INDEX IDX_D9C4B0DB25BAA637 ON purchase_invoice_item (purchase_invoice_id)');
        $this->addSql('CREATE INDEX IDX_D9C4B0DB32C8A3DE ON purchase_invoice_item (organization_id)');
        $this->addSql('ALTER TABLE purchase_invoice_item ADD CONSTRAINT FK_D9C4B0DB25BAA637 FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoice (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchase_invoice_item ADD CONSTRAINT FK_D9C4B0DB32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_invoice_item DROP CONSTRAINT FK_D9C4B0DB25BAA637');
        $this->addSql('ALTER TABLE purchase_invoice_item DROP CONSTRAINT FK_D9C4B0DB32C8A3DE');
        $this->addSql('DROP TABLE purchase_invoice_item');
    }
}
