<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The `name` alias key keeps the ABV from now on (ADR 0047), so the `name` aliases learned before point from keys
 * that put a 4% and a 0,5% beer together. They are not edited (a key is derived by the application's code, not by
 * SQL) but dropped, for every organization, and learned again from the confirmed items by
 * `app:purchasing:backfill-aliases` with the new key. The links that such an alias made, and nothing else
 * (`match_source = 'name'`), are taken back: the item is `unassigned` and the model has not been through it, so the
 * next matching pass looks at it again. A `confirmed` item, one taken off its product by a person and every link of
 * another source are not touched.
 */
final class Version20261127090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the name aliases keyed without the ABV and the unconfirmed links they made';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE purchased_item SET product_id = NULL, package_size = NULL, status = 'unassigned', match_source = NULL, match_caveat = NULL, matched_at = NULL WHERE match_source = 'name' AND status IN ('assigned', 'suggested') AND matching_skipped = false");
        $this->addSql("DELETE FROM product_alias WHERE kind = 'name'");
    }

    public function down(Schema $schema): void
    {
        // Dropped aliases and taken back links are derived data; `app:purchasing:backfill-aliases` and a matching pass make them again.
        $this->throwIrreversibleMigrationException('The aliases and the links are made again by the backfill and matching, not restored.');
    }
}
