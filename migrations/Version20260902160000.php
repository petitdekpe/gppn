<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire le fichier vidéo source et tout ce qui n'existait que pour son
 * pipeline de traitement (hash, vignette, HLS) : la vidéo est désormais
 * fournie directement via les variantes MP4 1080p / 480p (voir video_file),
 * sans étape de transcodage intermédiaire.
 */
final class Version20260902160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retire video.source_file_name/source_file_size/source_sha256/thumbnail_path/hls_playlist_path/updated_at.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video DROP source_file_name, DROP source_file_size, DROP source_sha256, DROP thumbnail_path, DROP hls_playlist_path, DROP updated_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video ADD source_file_name VARCHAR(255) DEFAULT NULL, ADD source_file_size INT DEFAULT NULL, ADD source_sha256 VARCHAR(64) DEFAULT NULL, ADD thumbnail_path VARCHAR(255) DEFAULT NULL, ADD hls_playlist_path VARCHAR(255) DEFAULT NULL, ADD updated_at DATETIME DEFAULT NULL');
    }
}
