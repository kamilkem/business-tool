<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111184217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shifts: time tracking, at most one open shift per person per organization (#24)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE shift (id UUID NOT NULL, user_id UUID NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, note TEXT DEFAULT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX shift_one_open_per_user ON shift (organization_id, user_id) WHERE (ended_at IS NULL)');
        $this->addSql('CREATE INDEX IDX_A50B3B4532C8A3DE ON shift (organization_id)');
        $this->addSql('ALTER TABLE shift ADD CONSTRAINT FK_A50B3B4532C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE shift DROP CONSTRAINT FK_A50B3B4532C8A3DE');
        $this->addSql('DROP TABLE shift');
    }
}
