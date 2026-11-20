<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120184900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Organization CSV separator for exports (#65), semicolon for countries with a decimal comma';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization ADD csv_separator VARCHAR(16) DEFAULT \'comma\' NOT NULL');
        // Same list as CsvSeparator::defaultFor().
        $this->addSql("UPDATE organization SET csv_separator = 'semicolon' WHERE country_code IN ('AT', 'BA', 'BE', 'BG', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IT', 'LT', 'LU', 'LV', 'NL', 'NO', 'PL', 'PT', 'RO', 'RS', 'SE', 'SI', 'SK', 'UA')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization DROP csv_separator');
    }
}
