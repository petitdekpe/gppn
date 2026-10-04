<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mots-clés des sujets (référencement et barre de recherche).
 */
final class Version20261004100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les mots-clés à la table subject.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subject ADD keywords LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subject DROP keywords');
    }
}
