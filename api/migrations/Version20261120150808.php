<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261120150808 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payroll: hourly rates from a business date and day rates per member (ADR 0027 §3, #60)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE day_rate (id UUID NOT NULL, user_id UUID NOT NULL, business_date DATE NOT NULL, amount INT NOT NULL, currency VARCHAR(3) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX day_rate_one_per_user_and_date ON day_rate (organization_id, user_id, business_date)');
        $this->addSql('CREATE INDEX IDX_7484D24F32C8A3DE ON day_rate (organization_id)');
        $this->addSql('CREATE TABLE hourly_rate (id UUID NOT NULL, user_id UUID NOT NULL, effective_from DATE NOT NULL, amount INT NOT NULL, currency VARCHAR(3) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX hourly_rate_one_per_user_and_date ON hourly_rate (organization_id, user_id, effective_from)');
        $this->addSql('CREATE INDEX IDX_D1ED4BBC32C8A3DE ON hourly_rate (organization_id)');
        $this->addSql('ALTER TABLE day_rate ADD CONSTRAINT FK_7484D24F32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE hourly_rate ADD CONSTRAINT FK_D1ED4BBC32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE day_rate DROP CONSTRAINT FK_7484D24F32C8A3DE');
        $this->addSql('ALTER TABLE hourly_rate DROP CONSTRAINT FK_D1ED4BBC32C8A3DE');
        $this->addSql('DROP TABLE day_rate');
        $this->addSql('DROP TABLE hourly_rate');
    }
}
