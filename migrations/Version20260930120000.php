<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Couverture tirée automatiquement de la vidéo TV : l'indicateur la
 * distingue d'une image déposée à la main, qui n'est jamais remplacée d'office.
 */
final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute video.cover_generated (couverture tirée de la vidéo TV).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video ADD cover_generated TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video DROP cover_generated');
    }
}
