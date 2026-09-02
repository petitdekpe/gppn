<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un contenu peut désormais offrir plusieurs fichiers de téléchargement
 * (MP4 1080p, MP4 480p, MP4 vertical, audio, PDF, image) au lieu d'un seul :
 * remplace video.download_file_* par la table video_file (une ligne par
 * type de fichier). Les fichiers existants sont repris dans video_file avec
 * un type déduit du format actuel de la vidéo.
 */
final class Version20260902140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remplace video.download_file_* par la table video_file (plusieurs fichiers par contenu).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE video_file (id INT AUTO_INCREMENT NOT NULL, video_id INT NOT NULL, type VARCHAR(255) NOT NULL, file_name VARCHAR(255) DEFAULT NULL, file_size INT DEFAULT NULL, mime_type VARCHAR(100) DEFAULT NULL, original_name VARCHAR(255) DEFAULT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_VIDEO_FILE_TYPE (video_id, type), INDEX IDX_8B086BCC29C1004E (video_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE video_file ADD CONSTRAINT FK_8B086BCC29C1004E FOREIGN KEY (video_id) REFERENCES video (id)');

        $this->addSql(<<<'SQL'
            INSERT INTO video_file (video_id, type, file_name, file_size, mime_type, original_name)
            SELECT
                id,
                CASE format
                    WHEN 'video' THEN '1_mp4_1080p'
                    WHEN 'audio' THEN '4_audio'
                    WHEN 'pdf' THEN '5_pdf'
                    WHEN 'image' THEN '6_image'
                END,
                download_file_name, download_file_size, download_file_mime_type, download_file_original_name
            FROM video
            WHERE download_file_name IS NOT NULL
            SQL);

        $this->addSql('ALTER TABLE video DROP download_file_name');
        $this->addSql('ALTER TABLE video DROP download_file_size');
        $this->addSql('ALTER TABLE video DROP download_file_mime_type');
        $this->addSql('ALTER TABLE video DROP download_file_original_name');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video ADD download_file_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD download_file_size INT DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD download_file_mime_type VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE video ADD download_file_original_name VARCHAR(255) DEFAULT NULL');

        $this->addSql(<<<'SQL'
            UPDATE video v
            INNER JOIN (
                SELECT video_id, MIN(id) AS id
                FROM video_file
                WHERE file_name IS NOT NULL
                GROUP BY video_id
            ) first_file ON first_file.video_id = v.id
            INNER JOIN video_file f ON f.id = first_file.id
            SET
                v.download_file_name = f.file_name,
                v.download_file_size = f.file_size,
                v.download_file_mime_type = f.mime_type,
                v.download_file_original_name = f.original_name
            SQL);

        $this->addSql('ALTER TABLE video_file DROP FOREIGN KEY FK_8B086BCC29C1004E');
        $this->addSql('DROP TABLE video_file');
    }
}
