<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Version WebM de lecture générée pour les vidéos TV/Mobile, l'original
 * restant le fichier téléchargeable.
 */
final class Version20260926173013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les colonnes de la version WebM de lecture à video_file.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_file ADD webm_file_name VARCHAR(255) DEFAULT NULL, ADD webm_file_size INT DEFAULT NULL, ADD webm_status VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_file DROP webm_file_name, DROP webm_file_size, DROP webm_status');
    }
}
