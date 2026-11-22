<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Cash storages per organization (#180, ADR 0033 §3). Every existing organization gets the default
 * storages of its country, as App\CashFlow\Domain\Model\DefaultCashStorages lists them today (copied
 * here, so later changes of the defaults do not change this migration): "Kasetka" (counted) and
 * "Sejf" (deposit) in Poland, "Cash box" (counted) and "Safe" (deposit) elsewhere.
 */
final class Version20261122094335 extends AbstractMigration
{
    /** @var list<array{name: string, mode: string}> */
    private const array FALLBACK = [
        ['name' => 'Cash box', 'mode' => 'counted'],
        ['name' => 'Safe', 'mode' => 'deposit'],
    ];

    /** @var array<string, list<array{name: string, mode: string}>> country code => storages */
    private const array BY_COUNTRY = [
        'PL' => [
            ['name' => 'Kasetka', 'mode' => 'counted'],
            ['name' => 'Sejf', 'mode' => 'deposit'],
        ],
    ];

    public function getDescription(): string
    {
        return 'Cash storages per organization (#180, ADR 0033 §3), with the defaults of each existing organization\'s country';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cash_storage (id UUID NOT NULL, name VARCHAR(64) NOT NULL, mode VARCHAR(8) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deactivated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, organization_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX cash_storage_name_unique ON cash_storage (organization_id, name)');
        $this->addSql('CREATE INDEX IDX_A96096832C8A3DE ON cash_storage (organization_id)');
        $this->addSql('ALTER TABLE cash_storage ADD CONSTRAINT FK_A96096832C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');

        // UUID v7 as the application generates them (ADR 0009).
        /** @var list<array{id: string, country_code: string}> $organizations */
        $organizations = $this->connection->fetchAllAssociative('SELECT id, country_code FROM organization ORDER BY id');
        foreach ($organizations as $organization) {
            foreach (self::BY_COUNTRY[strtoupper($organization['country_code'])] ?? self::FALLBACK as $storage) {
                $this->addSql(
                    "INSERT INTO cash_storage (id, name, mode, created_at, deactivated_at, organization_id) VALUES (:id, :name, :mode, (NOW() AT TIME ZONE 'UTC'), NULL, :organizationId)",
                    [
                        'id' => Uuid::v7()->toRfc4122(),
                        'name' => $storage['name'],
                        'mode' => $storage['mode'],
                        'organizationId' => $organization['id'],
                    ],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cash_storage DROP CONSTRAINT FK_A96096832C8A3DE');
        $this->addSql('DROP TABLE cash_storage');
    }
}
