<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122143822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cash flow: end-of-day closes with their storages, lines and non-cash amounts (#185, ADR 0033 §6).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cash_day_close (id UUID NOT NULL, business_date DATE NOT NULL, register_id UUID NOT NULL, currency VARCHAR(3) NOT NULL, cash_income BIGINT NOT NULL, closed_by UUID NOT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX cash_day_close_ledger_order ON cash_day_close (closed_at, id)');
        $this->addSql('CREATE UNIQUE INDEX cash_day_close_date_unique ON cash_day_close (organization_id, business_date)');
        $this->addSql('CREATE INDEX IDX_4F7B00DC32C8A3DE ON cash_day_close (organization_id)');
        $this->addSql('CREATE TABLE cash_day_close_line (pieces INT NOT NULL, day_close_id UUID NOT NULL, storage_id UUID NOT NULL, denomination_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (day_close_id, storage_id, denomination_id))');
        $this->addSql('CREATE INDEX cash_day_close_line_storage ON cash_day_close_line (storage_id)');
        $this->addSql('CREATE INDEX cash_day_close_line_denomination ON cash_day_close_line (denomination_id)');
        $this->addSql('CREATE INDEX IDX_EF7182CEA636455F ON cash_day_close_line (day_close_id)');
        $this->addSql('CREATE INDEX IDX_EF7182CE32C8A3DE ON cash_day_close_line (organization_id)');
        $this->addSql('CREATE TABLE cash_day_close_payment (payment_method_id UUID NOT NULL, amount INT NOT NULL, day_close_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (day_close_id, payment_method_id))');
        $this->addSql('CREATE INDEX IDX_F39434B9A636455F ON cash_day_close_payment (day_close_id)');
        $this->addSql('CREATE INDEX IDX_F39434B932C8A3DE ON cash_day_close_payment (organization_id)');
        $this->addSql('CREATE TABLE cash_day_close_storage (mode VARCHAR(8) NOT NULL, total BIGINT NOT NULL, expected_opening BIGINT DEFAULT NULL, day_close_id UUID NOT NULL, storage_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (day_close_id, storage_id))');
        $this->addSql('CREATE INDEX cash_day_close_storage_storage ON cash_day_close_storage (storage_id)');
        $this->addSql('CREATE INDEX IDX_CAC6AB80A636455F ON cash_day_close_storage (day_close_id)');
        $this->addSql('CREATE INDEX IDX_CAC6AB8032C8A3DE ON cash_day_close_storage (organization_id)');
        $this->addSql('ALTER TABLE cash_day_close ADD CONSTRAINT FK_4F7B00DC32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cash_day_close_line ADD CONSTRAINT FK_EF7182CEA636455F FOREIGN KEY (day_close_id) REFERENCES cash_day_close (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cash_day_close_line ADD CONSTRAINT FK_EF7182CE5CC5DB90 FOREIGN KEY (storage_id) REFERENCES cash_storage (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cash_day_close_line ADD CONSTRAINT FK_EF7182CEE9293F06 FOREIGN KEY (denomination_id) REFERENCES cash_denomination (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cash_day_close_line ADD CONSTRAINT FK_EF7182CE32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cash_day_close_payment ADD CONSTRAINT FK_F39434B9A636455F FOREIGN KEY (day_close_id) REFERENCES cash_day_close (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cash_day_close_payment ADD CONSTRAINT FK_F39434B932C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cash_day_close_storage ADD CONSTRAINT FK_CAC6AB80A636455F FOREIGN KEY (day_close_id) REFERENCES cash_day_close (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cash_day_close_storage ADD CONSTRAINT FK_CAC6AB805CC5DB90 FOREIGN KEY (storage_id) REFERENCES cash_storage (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE cash_day_close_storage ADD CONSTRAINT FK_CAC6AB8032C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_day_close DROP CONSTRAINT FK_4F7B00DC32C8A3DE');
        $this->addSql('ALTER TABLE cash_day_close_line DROP CONSTRAINT FK_EF7182CEA636455F');
        $this->addSql('ALTER TABLE cash_day_close_line DROP CONSTRAINT FK_EF7182CE5CC5DB90');
        $this->addSql('ALTER TABLE cash_day_close_line DROP CONSTRAINT FK_EF7182CEE9293F06');
        $this->addSql('ALTER TABLE cash_day_close_line DROP CONSTRAINT FK_EF7182CE32C8A3DE');
        $this->addSql('ALTER TABLE cash_day_close_payment DROP CONSTRAINT FK_F39434B9A636455F');
        $this->addSql('ALTER TABLE cash_day_close_payment DROP CONSTRAINT FK_F39434B932C8A3DE');
        $this->addSql('ALTER TABLE cash_day_close_storage DROP CONSTRAINT FK_CAC6AB80A636455F');
        $this->addSql('ALTER TABLE cash_day_close_storage DROP CONSTRAINT FK_CAC6AB805CC5DB90');
        $this->addSql('ALTER TABLE cash_day_close_storage DROP CONSTRAINT FK_CAC6AB8032C8A3DE');
        $this->addSql('DROP TABLE cash_day_close');
        $this->addSql('DROP TABLE cash_day_close_line');
        $this->addSql('DROP TABLE cash_day_close_payment');
        $this->addSql('DROP TABLE cash_day_close_storage');
    }
}
