<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Ce que vous apprendrez dans ce contenu » se saisit désormais une seule
 * fois sur le sujet, comme le titre et le résumé, plutôt que dupliqué sur
 * chaque contenu.
 */
final class Version20260905093935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Déplace learning_points de video vers subject.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subject ADD learning_points LONGTEXT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE
              subject s
              INNER JOIN video v ON v.subject_id = s.id
            SET
              s.learning_points = v.learning_points
        SQL);
        $this->addSql('ALTER TABLE video DROP learning_points');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video ADD learning_points LONGTEXT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE
              video v
              INNER JOIN subject s ON s.id = v.subject_id
            SET
              v.learning_points = s.learning_points
        SQL);
        $this->addSql('ALTER TABLE subject DROP learning_points');
    }
}
