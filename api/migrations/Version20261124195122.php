<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124195122 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The date of issue from which KSeF purchase invoices are imported, chosen by an owner or manager (ADR 0038).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ksef_connection ADD sync_start_date DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ksef_connection DROP sync_start_date');
    }
}
