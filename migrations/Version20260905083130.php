<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le titre et le résumé se saisissent désormais une seule fois sur le sujet
 * (partagés par toutes ses langues) plutôt que dupliqués sur chaque contenu.
 * `subject.reference_title` devient `subject.title` (déjà rempli depuis
 * video.title par la migration précédente) ; `subject.summary` est backfillé
 * depuis un contenu de chaque sujet avant que video.title/summary ne soient
 * retirés.
 */
final class Version20260905083130 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Déplace title/summary de video vers subject (reference_title renommé en title).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subject CHANGE reference_title title VARCHAR(200) NOT NULL');
        $this->addSql('ALTER TABLE subject ADD summary LONGTEXT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE
              subject s
              INNER JOIN video v ON v.subject_id = s.id
            SET
              s.summary = v.summary
        SQL);
        $this->addSql("UPDATE subject SET summary = '' WHERE summary IS NULL");
        $this->addSql('ALTER TABLE subject CHANGE summary summary LONGTEXT NOT NULL');

        $this->addSql('ALTER TABLE video DROP title, DROP summary');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video ADD title VARCHAR(200) DEFAULT NULL, ADD summary LONGTEXT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE
              video v
              INNER JOIN subject s ON s.id = v.subject_id
            SET
              v.title = s.title,
              v.summary = s.summary
        SQL);
        $this->addSql('ALTER TABLE video CHANGE title title VARCHAR(200) NOT NULL, CHANGE summary summary LONGTEXT NOT NULL');

        $this->addSql('ALTER TABLE subject DROP summary');
        $this->addSql('ALTER TABLE subject CHANGE title reference_title VARCHAR(200) NOT NULL');
    }
}
