<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vidéos défectueuses : un fichier illisible (confirmé par ffmpeg après un
 * échec de lecture dans un navigateur) est masqué du site public.
 */
final class Version20261001100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le diagnostic de lecture des fichiers (defective_at, defect_reason, checked_at).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_file ADD defective_at DATETIME DEFAULT NULL, ADD defect_reason VARCHAR(255) DEFAULT NULL, ADD checked_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_file DROP defective_at, DROP defect_reason, DROP checked_at');
    }
}
