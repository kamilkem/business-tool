<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112145608 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Income: registers and revenue entries with payment and VAT lines (#41, ADR 0022)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE register (id UUID NOT NULL, name VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deactivated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX register_name_unique ON register (organization_id, name)');
        $this->addSql('CREATE INDEX IDX_5FF9401432C8A3DE ON register (organization_id)');
        $this->addSql('CREATE TABLE revenue_entry (id UUID NOT NULL, business_date DATE NOT NULL, currency VARCHAR(3) NOT NULL, note TEXT DEFAULT NULL, created_by UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_by UUID DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, register_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX revenue_entry_organization_business_date ON revenue_entry (organization_id, business_date)');
        $this->addSql('CREATE UNIQUE INDEX revenue_entry_one_per_register_and_date ON revenue_entry (organization_id, register_id, business_date)');
        $this->addSql('CREATE INDEX IDX_FD30B51E4976CB7E ON revenue_entry (register_id)');
        $this->addSql('CREATE INDEX IDX_FD30B51E32C8A3DE ON revenue_entry (organization_id)');
        $this->addSql('CREATE TABLE revenue_entry_payment_line (method VARCHAR(16) NOT NULL, amount INT NOT NULL, revenue_entry_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (revenue_entry_id, method))');
        $this->addSql('CREATE INDEX IDX_C5B88980CF9221E6 ON revenue_entry_payment_line (revenue_entry_id)');
        $this->addSql('CREATE INDEX IDX_C5B8898032C8A3DE ON revenue_entry_payment_line (organization_id)');
        $this->addSql('CREATE TABLE revenue_entry_vat_line (rate VARCHAR(8) NOT NULL, gross INT NOT NULL, vat INT NOT NULL, revenue_entry_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (revenue_entry_id, rate))');
        $this->addSql('CREATE INDEX IDX_2DC7D7DDCF9221E6 ON revenue_entry_vat_line (revenue_entry_id)');
        $this->addSql('CREATE INDEX IDX_2DC7D7DD32C8A3DE ON revenue_entry_vat_line (organization_id)');
        $this->addSql('ALTER TABLE register ADD CONSTRAINT FK_5FF9401432C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE revenue_entry ADD CONSTRAINT FK_FD30B51E4976CB7E FOREIGN KEY (register_id) REFERENCES register (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE revenue_entry ADD CONSTRAINT FK_FD30B51E32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE revenue_entry_payment_line ADD CONSTRAINT FK_C5B88980CF9221E6 FOREIGN KEY (revenue_entry_id) REFERENCES revenue_entry (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE revenue_entry_payment_line ADD CONSTRAINT FK_C5B8898032C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE revenue_entry_vat_line ADD CONSTRAINT FK_2DC7D7DDCF9221E6 FOREIGN KEY (revenue_entry_id) REFERENCES revenue_entry (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE revenue_entry_vat_line ADD CONSTRAINT FK_2DC7D7DD32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE register DROP CONSTRAINT FK_5FF9401432C8A3DE');
        $this->addSql('ALTER TABLE revenue_entry DROP CONSTRAINT FK_FD30B51E4976CB7E');
        $this->addSql('ALTER TABLE revenue_entry DROP CONSTRAINT FK_FD30B51E32C8A3DE');
        $this->addSql('ALTER TABLE revenue_entry_payment_line DROP CONSTRAINT FK_C5B88980CF9221E6');
        $this->addSql('ALTER TABLE revenue_entry_payment_line DROP CONSTRAINT FK_C5B8898032C8A3DE');
        $this->addSql('ALTER TABLE revenue_entry_vat_line DROP CONSTRAINT FK_2DC7D7DDCF9221E6');
        $this->addSql('ALTER TABLE revenue_entry_vat_line DROP CONSTRAINT FK_2DC7D7DD32C8A3DE');
        $this->addSql('DROP TABLE register');
        $this->addSql('DROP TABLE revenue_entry');
        $this->addSql('DROP TABLE revenue_entry_payment_line');
        $this->addSql('DROP TABLE revenue_entry_vat_line');
    }
}
