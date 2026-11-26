<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * What a seller's unit of unknown count holds, as the owner confirmed it (`seller_unit`, ADR 0043).
 */
final class Version20261126105224 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchasing: seller units, the pieces a seller\'s case or carton holds (ADR 0043)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE seller_unit (id UUID NOT NULL, seller_key VARCHAR(300) NOT NULL, unit_key VARCHAR(64) NOT NULL, multiplier INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX seller_unit_unique_unit ON seller_unit (organization_id, seller_key, unit_key)');
        $this->addSql('CREATE INDEX IDX_DE42C9B332C8A3DE ON seller_unit (organization_id)');
        $this->addSql('ALTER TABLE seller_unit ADD CONSTRAINT FK_DE42C9B332C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE seller_unit DROP CONSTRAINT FK_DE42C9B332C8A3DE');
        $this->addSql('DROP TABLE seller_unit');
    }
}
