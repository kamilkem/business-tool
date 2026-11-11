<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111210452 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shifts: index for listing by business date (#26)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX shift_organization_started_at ON shift (organization_id, started_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX shift_organization_started_at');
    }
}
