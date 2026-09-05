<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute speaker.sigle : sigle du ministère (ex. « MTCA »), affiché devant
 * le nom de l'intervenant dans le sélecteur du formulaire vidéo.
 */
final class Version20260905094654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute speaker.sigle.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE speaker ADD sigle VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE speaker DROP sigle');
    }
}
