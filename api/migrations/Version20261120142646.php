<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261120142646 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Time tracking: locked shift periods, set when a payroll month is approved (ADR 0027 §8, #138)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE locked_shift_period (id UUID NOT NULL, locked_by UUID NOT NULL, first_date DATE NOT NULL, last_date DATE NOT NULL, locked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX locked_shift_period_one_per_locker ON locked_shift_period (organization_id, locked_by)');
        $this->addSql('CREATE INDEX IDX_FB9BD61632C8A3DE ON locked_shift_period (organization_id)');
        $this->addSql('ALTER TABLE locked_shift_period ADD CONSTRAINT FK_FB9BD61632C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE locked_shift_period DROP CONSTRAINT FK_FB9BD61632C8A3DE');
        $this->addSql('DROP TABLE locked_shift_period');
    }
}
