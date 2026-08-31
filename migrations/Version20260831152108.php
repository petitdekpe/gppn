<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260831152108 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le format de capsule (vidéo / audio / pdf / image) sur les vidéos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE video ADD format VARCHAR(255) NOT NULL DEFAULT 'video'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE video DROP format');
    }
}
