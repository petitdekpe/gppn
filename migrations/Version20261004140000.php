<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pages modifiables depuis l'admin (confidentialité, mentions légales,
 * contact) et messages « Nous contacter » rangés avec les suggestions.
 */
final class Version20261004140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table site_page et ajoute le type et le téléphone à la table suggestion.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE site_page (slug VARCHAR(40) NOT NULL, title VARCHAR(150) NOT NULL, content LONGTEXT NOT NULL, updated_at DATETIME DEFAULT NULL, updated_by_id INT DEFAULT NULL, INDEX IDX_2F900BD9896DBBDE (updated_by_id), PRIMARY KEY (slug)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE site_page ADD CONSTRAINT FK_2F900BD9896DBBDE FOREIGN KEY (updated_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE suggestion ADD phone VARCHAR(30) DEFAULT NULL, ADD kind VARCHAR(20) DEFAULT \'suggestion\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_page DROP FOREIGN KEY FK_2F900BD9896DBBDE');
        $this->addSql('DROP TABLE site_page');
        $this->addSql('ALTER TABLE suggestion DROP phone, DROP kind');
    }
}
