<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un contenu ne porte plus qu'un seul intervenant (liste déroulante côté
 * admin) : la relation many-to-many video_speaker devient un many-to-one
 * video.speaker_id. Pour les vidéos qui avaient plusieurs intervenants, on
 * conserve celui dont l'identifiant est le plus petit (le premier ajouté).
 */
final class Version20260902122915 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remplace la relation many-to-many video_speaker par video.speaker_id (un seul intervenant par contenu).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video ADD speaker_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE video v
            INNER JOIN (
                SELECT video_id, MIN(speaker_id) AS speaker_id
                FROM video_speaker
                GROUP BY video_id
            ) vs ON vs.video_id = v.id
            SET v.speaker_id = vs.speaker_id
        SQL);

        $this->addSql('ALTER TABLE video_speaker DROP FOREIGN KEY `FK_9E6832CC29C1004E`');
        $this->addSql('ALTER TABLE video_speaker DROP FOREIGN KEY `FK_9E6832CCD04A0F27`');
        $this->addSql('DROP TABLE video_speaker');

        $this->addSql(<<<'SQL'
            ALTER TABLE
              video
            ADD
              CONSTRAINT FK_7CC7DA2CD04A0F27 FOREIGN KEY (speaker_id) REFERENCES speaker (id)
        SQL);
        $this->addSql('CREATE INDEX IDX_7CC7DA2CD04A0F27 ON video (speaker_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE video_speaker (
              video_id INT NOT NULL,
              speaker_id INT NOT NULL,
              INDEX IDX_9E6832CC29C1004E (video_id),
              INDEX IDX_9E6832CCD04A0F27 (speaker_id),
              PRIMARY KEY (video_id, speaker_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = ''
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_speaker
            ADD
              CONSTRAINT `FK_9E6832CC29C1004E` FOREIGN KEY (video_id) REFERENCES video (id) ON
            UPDATE
              NO ACTION ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video_speaker
            ADD
              CONSTRAINT `FK_9E6832CCD04A0F27` FOREIGN KEY (speaker_id) REFERENCES speaker (id) ON
            UPDATE
              NO ACTION ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO video_speaker (video_id, speaker_id)
            SELECT id, speaker_id FROM video WHERE speaker_id IS NOT NULL
        SQL);

        $this->addSql('ALTER TABLE video DROP FOREIGN KEY FK_7CC7DA2CD04A0F27');
        $this->addSql('DROP INDEX IDX_7CC7DA2CD04A0F27 ON video');
        $this->addSql('ALTER TABLE video DROP speaker_id');
    }
}
