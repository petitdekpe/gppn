<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Espace presse : coordonnées des comptes médias inscrits depuis le site
 * (nom, média, type de média, téléphone, date d'inscription).
 */
final class Version20261003140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les coordonnées des comptes médias (espace presse) à la table user.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD full_name VARCHAR(150) DEFAULT NULL, ADD organization VARCHAR(150) DEFAULT NULL, ADD media_type VARCHAR(30) DEFAULT NULL, ADD phone VARCHAR(30) DEFAULT NULL, ADD registered_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP full_name, DROP organization, DROP media_type, DROP phone, DROP registered_at');
    }
}
