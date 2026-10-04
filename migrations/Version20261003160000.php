<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Portrait et rang protocolaire des intervenants (page « Les ministres »).
 */
final class Version20261003160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la photo et le rang de préséance des intervenants à la table speaker.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE speaker ADD photo_name VARCHAR(255) DEFAULT NULL, ADD photo_updated_at DATETIME DEFAULT NULL, ADD precedence SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE speaker DROP photo_name, DROP photo_updated_at, DROP precedence');
    }
}
