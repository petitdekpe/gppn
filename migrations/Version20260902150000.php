<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un contenu n'a plus de format unique déclaré : ce sont les fichiers
 * (video_file) réellement déposés qui déterminent ce qui peut être proposé
 * (lecteur, aperçu, filtre par format). Le statut est simplifié à 3 valeurs
 * éditoriales (Brouillon/Publié/Masqué) : le pipeline de traitement ne pilote
 * plus le statut, qui redevient entièrement une décision de l'admin.
 */
final class Version20260902150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retire video.format ; simplifie video.status à brouillon/publie/masque.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE video SET status = 'brouillon' WHERE status IN ('en_traitement', 'en_relecture', 'echec')");
        $this->addSql('ALTER TABLE video DROP format');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE video ADD format VARCHAR(255) DEFAULT 'video' NOT NULL");
    }
}
