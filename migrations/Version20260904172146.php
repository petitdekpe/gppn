<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute video.cover_position_x/y (0-100) : point de cadrage de l'image de
 * couverture affichée en object-fit: cover (card, à la une, fiche détail),
 * réglé à la souris (glisser-déposer) par contenu depuis l'admin.
 */
final class Version20260904172146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute video.cover_position_x/y pour le cadrage de la couverture par contenu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video ADD cover_position_x INT DEFAULT 50 NOT NULL, ADD cover_position_y INT DEFAULT 50 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video DROP cover_position_x, DROP cover_position_y');
    }
}
