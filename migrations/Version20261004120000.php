<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Espace média : préférences de lot des médias (trois au plus par compte)
 * et choix « Ne plus me proposer » d'enregistrer ses préférences.
 */
final class Version20261004120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table lot_preference et ajoute lot_preference_prompt à la table user.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE lot_preference (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(60) NOT NULL, language_ids JSON NOT NULL, formats JSON NOT NULL, speaker VARCHAR(150) DEFAULT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_2CF923E4A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE lot_preference ADD CONSTRAINT FK_2CF923E4A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE `user` ADD lot_preference_prompt TINYINT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lot_preference DROP FOREIGN KEY FK_2CF923E4A76ED395');
        $this->addSql('DROP TABLE lot_preference');
        $this->addSql('ALTER TABLE `user` DROP lot_preference_prompt');
    }
}
