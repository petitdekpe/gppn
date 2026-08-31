<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260831160429 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le statut de capsule et les métadonnées du pipeline de traitement vidéo (fichier source, SHA-256, vignette, playlist HLS) sur video.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video ADD status VARCHAR(255) DEFAULT \'publie\' NOT NULL, ADD source_file_name VARCHAR(255) DEFAULT NULL, ADD source_file_size INT DEFAULT NULL, ADD source_sha256 VARCHAR(64) DEFAULT NULL, ADD thumbnail_path VARCHAR(255) DEFAULT NULL, ADD hls_playlist_path VARCHAR(255) DEFAULT NULL, ADD updated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video DROP status, DROP source_file_name, DROP source_file_size, DROP source_sha256, DROP thumbnail_path, DROP hls_playlist_path, DROP updated_at');
    }
}
