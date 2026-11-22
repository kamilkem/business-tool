<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261122164308 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cash flow: who corrected an end-of-day close last, and when (#189).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_day_close ADD updated_by UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE cash_day_close ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_day_close DROP updated_by');
        $this->addSql('ALTER TABLE cash_day_close DROP updated_at');
    }
}
