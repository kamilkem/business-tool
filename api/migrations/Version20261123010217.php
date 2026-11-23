<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123010217 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'When the requester withdrew a shift change request (status withdrawn, #102).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shift_change_request ADD withdrawn_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shift_change_request DROP withdrawn_at');
    }
}
