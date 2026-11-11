<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111234308 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shifts: source, and who entered or last corrected them and when (#27)';
    }

    public function up(Schema $schema): void
    {
        // Every existing shift was clocked in; the default only fills them and is dropped to match the mapping.
        $this->addSql("ALTER TABLE shift ADD source VARCHAR(16) DEFAULT 'clock' NOT NULL");
        $this->addSql('ALTER TABLE shift ALTER source DROP DEFAULT');
        $this->addSql('ALTER TABLE shift ADD corrected_by UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE shift ADD corrected_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shift DROP source');
        $this->addSql('ALTER TABLE shift DROP corrected_by');
        $this->addSql('ALTER TABLE shift DROP corrected_at');
    }
}
