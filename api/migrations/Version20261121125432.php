<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261121125432 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'User UI language (#151, ADR 0028), null until the user chooses one';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD language VARCHAR(8) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP language');
    }
}
