<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261120180801 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Payroll: payroll runs of a month with paychecks, shift lines and adjustments (ADR 0027 §4–9, #62)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE paycheck (id UUID NOT NULL, user_id UUID NOT NULL, payroll_run_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX paycheck_one_per_run_and_user ON paycheck (payroll_run_id, user_id)');
        $this->addSql('CREATE INDEX IDX_32320EAFA5FC78E ON paycheck (payroll_run_id)');
        $this->addSql('CREATE INDEX IDX_32320EAF32C8A3DE ON paycheck (organization_id)');
        $this->addSql('CREATE TABLE paycheck_adjustment (label VARCHAR(100) NOT NULL, amount INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, paycheck_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_51D14A07E231A351 ON paycheck_adjustment (paycheck_id)');
        $this->addSql('CREATE INDEX IDX_51D14A0732C8A3DE ON paycheck_adjustment (organization_id)');
        $this->addSql('CREATE TABLE paycheck_line (date DATE NOT NULL, seconds INT NOT NULL, paid_seconds INT NOT NULL, rate_kind VARCHAR(8) DEFAULT NULL, rate_id UUID DEFAULT NULL, rate INT DEFAULT NULL, shift_id UUID NOT NULL, paycheck_id UUID NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (paycheck_id, shift_id))');
        $this->addSql('CREATE INDEX IDX_5406DE16E231A351 ON paycheck_line (paycheck_id)');
        $this->addSql('CREATE INDEX IDX_5406DE1632C8A3DE ON paycheck_line (organization_id)');
        $this->addSql('CREATE TABLE payroll_run (id UUID NOT NULL, month DATE NOT NULL, status VARCHAR(16) NOT NULL, currency VARCHAR(3) NOT NULL, rounding_unit VARCHAR(8) NOT NULL, rounding_direction VARCHAR(8) NOT NULL, open_shifts INT NOT NULL, pending_change_requests INT NOT NULL, created_by UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, generated_by UUID NOT NULL, generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, approved_by UUID DEFAULT NULL, approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX payroll_run_one_per_month ON payroll_run (organization_id, month)');
        $this->addSql('CREATE INDEX IDX_7BA6647532C8A3DE ON payroll_run (organization_id)');
        $this->addSql('ALTER TABLE paycheck ADD CONSTRAINT FK_32320EAFA5FC78E FOREIGN KEY (payroll_run_id) REFERENCES payroll_run (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE paycheck ADD CONSTRAINT FK_32320EAF32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE paycheck_adjustment ADD CONSTRAINT FK_51D14A07E231A351 FOREIGN KEY (paycheck_id) REFERENCES paycheck (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE paycheck_adjustment ADD CONSTRAINT FK_51D14A0732C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE paycheck_line ADD CONSTRAINT FK_5406DE16E231A351 FOREIGN KEY (paycheck_id) REFERENCES paycheck (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE paycheck_line ADD CONSTRAINT FK_5406DE1632C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE payroll_run ADD CONSTRAINT FK_7BA6647532C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE paycheck DROP CONSTRAINT FK_32320EAFA5FC78E');
        $this->addSql('ALTER TABLE paycheck DROP CONSTRAINT FK_32320EAF32C8A3DE');
        $this->addSql('ALTER TABLE paycheck_adjustment DROP CONSTRAINT FK_51D14A07E231A351');
        $this->addSql('ALTER TABLE paycheck_adjustment DROP CONSTRAINT FK_51D14A0732C8A3DE');
        $this->addSql('ALTER TABLE paycheck_line DROP CONSTRAINT FK_5406DE16E231A351');
        $this->addSql('ALTER TABLE paycheck_line DROP CONSTRAINT FK_5406DE1632C8A3DE');
        $this->addSql('ALTER TABLE payroll_run DROP CONSTRAINT FK_7BA6647532C8A3DE');
        $this->addSql('DROP TABLE paycheck');
        $this->addSql('DROP TABLE paycheck_adjustment');
        $this->addSql('DROP TABLE paycheck_line');
        $this->addSql('DROP TABLE payroll_run');
    }
}
