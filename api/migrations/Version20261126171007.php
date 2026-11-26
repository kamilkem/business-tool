<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The record of matching runs that used the model (ADR 0046 §6): when each started and ended, how, and what the model did. Gone with the organization. */
final class Version20261126171007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchasing: matching runs (ADR 0046)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE matching_run (id UUID NOT NULL, scope VARCHAR(24) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, outcome VARCHAR(24) DEFAULT NULL, interpreted INT NOT NULL, assigned INT NOT NULL, suggested INT NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX matching_run_started_idx ON matching_run (organization_id, started_at)');
        $this->addSql('CREATE INDEX IDX_FEAB6F9B32C8A3DE ON matching_run (organization_id)');
        $this->addSql('ALTER TABLE matching_run ADD CONSTRAINT FK_FEAB6F9B32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE matching_run DROP CONSTRAINT FK_FEAB6F9B32C8A3DE');
        $this->addSql('DROP TABLE matching_run');
    }
}
