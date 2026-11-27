<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A purchased item, and the alias that remembers its package, can be a bundle (ADR 0048): `bundle_count` single
 * packages of `package_size` each in one unit of the quantity. Nothing changes for what exists: a null count means
 * the package size is per unit, as before.
 */
final class Version20261127120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the bundle count to purchased items and product aliases';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_alias ADD bundle_count INT DEFAULT NULL');
        $this->addSql('ALTER TABLE purchased_item ADD bundle_count INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_alias DROP bundle_count');
        $this->addSql('ALTER TABLE purchased_item DROP bundle_count');
    }
}
