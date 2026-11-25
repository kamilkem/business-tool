<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Purchasing's record of each invoice item (#240, ADR 0041 §3). Items stored before it existed are
 * copied by `app:purchasing:record-existing-items`, not by this migration: it queues a job, as
 * `app:ksef:read-invoice-items` does.
 */
final class Version20261125133719 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchasing: purchased items, one per invoice item (#240, ADR 0041 §3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE purchased_item (id UUID NOT NULL, invoice_id UUID NOT NULL, item_index INT NOT NULL, invoice_issue_date DATE NOT NULL, invoice_kind VARCHAR(32) NOT NULL, currency VARCHAR(3) NOT NULL, seller_tax_id VARCHAR(32) DEFAULT NULL, seller_name VARCHAR(512) NOT NULL, position INT NOT NULL, name VARCHAR(512) NOT NULL, seller_code VARCHAR(64) DEFAULT NULL, gtin VARCHAR(32) DEFAULT NULL, unit VARCHAR(256) DEFAULT NULL, quantity NUMERIC(22, 6) DEFAULT NULL, unit_net_price NUMERIC(22, 8) DEFAULT NULL, unit_gross_price NUMERIC(22, 8) DEFAULT NULL, discount NUMERIC(22, 8) DEFAULT NULL, net_amount BIGINT DEFAULT NULL, gross_amount BIGINT DEFAULT NULL, vat_amount BIGINT DEFAULT NULL, vat_rate VARCHAR(8) DEFAULT NULL, before_correction BOOLEAN NOT NULL, product_id UUID DEFAULT NULL, status VARCHAR(16) NOT NULL, package_size NUMERIC(22, 6) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX purchased_item_one_per_invoice_item ON purchased_item (invoice_id, item_index)');
        $this->addSql('CREATE INDEX IDX_F848214132C8A3DE ON purchased_item (organization_id)');
        $this->addSql('ALTER TABLE purchased_item ADD CONSTRAINT FK_F848214132C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchased_item DROP CONSTRAINT FK_F848214132C8A3DE');
        $this->addSql('DROP TABLE purchased_item');
    }
}
