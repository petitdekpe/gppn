<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Intervenants classés par gouvernement. Les intervenants déjà saisis sont
 * rangés dans un premier gouvernement, désigné comme actuel, à renommer
 * (et dater) depuis l'administration.
 */
final class Version20260930150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table government et rattache chaque intervenant à un gouvernement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE government (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(150) NOT NULL, started_at DATE DEFAULT NULL, is_current TINYINT DEFAULT 0 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE speaker ADD government_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE speaker ADD CONSTRAINT FK_7B85DB61F55836AA FOREIGN KEY (government_id) REFERENCES government (id)');
        $this->addSql('CREATE INDEX IDX_7B85DB61F55836AA ON speaker (government_id)');

        $this->addSql("INSERT INTO government (label, is_current) SELECT 'Gouvernement actuel', 1 FROM DUAL WHERE EXISTS (SELECT 1 FROM speaker)");
        $this->addSql('UPDATE speaker SET government_id = (SELECT id FROM government WHERE is_current = 1 LIMIT 1)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE speaker DROP FOREIGN KEY FK_7B85DB61F55836AA');
        $this->addSql('DROP INDEX IDX_7B85DB61F55836AA ON speaker');
        $this->addSql('ALTER TABLE speaker DROP government_id');
        $this->addSql('DROP TABLE government');
    }
}
