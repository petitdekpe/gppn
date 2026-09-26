<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire le cadrage manuel de la couverture (video.cover_position_x/y) au
 * profit d'une image de couverture déposée par l'admin (video.cover_image_*).
 */
final class Version20260905180015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remplace video.cover_position_x/y par une image de couverture dédiée (video.cover_image_*).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video DROP cover_position_x, DROP cover_position_y');
        $this->addSql('ALTER TABLE video ADD cover_image_name VARCHAR(255) DEFAULT NULL, ADD cover_image_size INT DEFAULT NULL, ADD cover_image_mime_type VARCHAR(100) DEFAULT NULL, ADD cover_image_original_name VARCHAR(255) DEFAULT NULL, ADD cover_image_updated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video DROP cover_image_name, DROP cover_image_size, DROP cover_image_mime_type, DROP cover_image_original_name, DROP cover_image_updated_at');
        $this->addSql('ALTER TABLE video ADD cover_position_x INT DEFAULT 50 NOT NULL, ADD cover_position_y INT DEFAULT 50 NOT NULL');
    }
}
