<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A revenue entry may be booked from a Cash flow end-of-day close (#186, ADR 0033 §9): the close's id,
 * at most one entry per close. Existing entries are manual (NULL).
 */
final class Version20261122151745 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Revenue entries: the end-of-day close an entry is booked from (#186, ADR 0033 §9)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE revenue_entry ADD day_close_id UUID DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FD30B51EA636455F ON revenue_entry (day_close_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_FD30B51EA636455F');
        $this->addSql('ALTER TABLE revenue_entry DROP day_close_id');
    }
}
