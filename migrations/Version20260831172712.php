<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remplace le champ texte "download_url" par un fichier stocké (Vich Uploader / Flysystem / S3).
 */
final class Version20260831172712 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remplace download_url par les colonnes de fichier stocké (Vich Uploader).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video DROP download_url, ADD download_file_name VARCHAR(255) DEFAULT NULL, ADD download_file_size INT DEFAULT NULL, ADD download_file_mime_type VARCHAR(100) DEFAULT NULL, ADD download_file_original_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video ADD download_url VARCHAR(255) DEFAULT NULL, DROP download_file_name, DROP download_file_size, DROP download_file_mime_type, DROP download_file_original_name');
    }
}
