<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Paramètres du site réglables depuis l'admin (OTP par e-mail, types de
 * contenus activés), stockés en clé/valeur.
 */
final class Version20260926165417 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table app_setting (paramètres du site).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_setting (name VARCHAR(100) NOT NULL, value JSON NOT NULL, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_setting');
    }
}
