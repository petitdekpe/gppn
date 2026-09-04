<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Introduit la table `subject` : un même sujet de conseil des ministres
 * (thématique + conseil) peut désormais porter plusieurs contenus (langues
 * différentes, ou plusieurs fois la même langue). Un sujet est créé pour
 * chaque contenu existant, avec `subject.id = video.id` pour que la reprise
 * de données se fasse sans boucle applicative (InnoDB relève ensuite
 * automatiquement le compteur AUTO_INCREMENT de `subject` au-delà du plus
 * grand id explicitement inséré).
 */
final class Version20260903104109 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la table subject (thematic + council_session) et rattache video.subject_id ; retire video.thematic_id/council_session_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE subject (
              id INT AUTO_INCREMENT NOT NULL,
              reference_title VARCHAR(200) NOT NULL,
              council_session_id INT NOT NULL,
              thematic_id INT NOT NULL,
              INDEX IDX_FBCE3E7AE8327BBB (council_session_id),
              INDEX IDX_FBCE3E7A2395FCED (thematic_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              subject
            ADD
              CONSTRAINT FK_FBCE3E7AE8327BBB FOREIGN KEY (council_session_id) REFERENCES council_session (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              subject
            ADD
              CONSTRAINT FK_FBCE3E7A2395FCED FOREIGN KEY (thematic_id) REFERENCES thematic (id)
        SQL);

        $this->addSql('INSERT INTO subject (id, council_session_id, thematic_id, reference_title) SELECT id, council_session_id, thematic_id, title FROM video');

        $this->addSql('ALTER TABLE video ADD subject_id INT DEFAULT NULL');
        $this->addSql('UPDATE video SET subject_id = id');

        $this->addSql('ALTER TABLE video DROP FOREIGN KEY `FK_7CC7DA2C2395FCED`');
        $this->addSql('ALTER TABLE video DROP FOREIGN KEY `FK_7CC7DA2CD9AAD86D`');
        $this->addSql('DROP INDEX IDX_7CC7DA2C2395FCED ON video');
        $this->addSql('DROP INDEX IDX_7CC7DA2CE8327BBB ON video');
        $this->addSql('ALTER TABLE video CHANGE subject_id subject_id INT NOT NULL, DROP thematic_id, DROP council_session_id');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video
            ADD
              CONSTRAINT FK_7CC7DA2C23EDC87 FOREIGN KEY (subject_id) REFERENCES subject (id)
        SQL);
        $this->addSql('CREATE INDEX IDX_7CC7DA2C23EDC87 ON video (subject_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video DROP FOREIGN KEY FK_7CC7DA2C23EDC87');
        $this->addSql('DROP INDEX IDX_7CC7DA2C23EDC87 ON video');
        $this->addSql('ALTER TABLE video ADD thematic_id INT DEFAULT NULL, ADD council_session_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE
              video v
              INNER JOIN subject s ON s.id = v.subject_id
            SET
              v.thematic_id = s.thematic_id,
              v.council_session_id = s.council_session_id
        SQL);
        $this->addSql('ALTER TABLE video CHANGE thematic_id thematic_id INT NOT NULL, CHANGE council_session_id council_session_id INT NOT NULL, DROP subject_id');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video
            ADD
              CONSTRAINT `FK_7CC7DA2C2395FCED` FOREIGN KEY (thematic_id) REFERENCES thematic (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              video
            ADD
              CONSTRAINT `FK_7CC7DA2CD9AAD86D` FOREIGN KEY (council_session_id) REFERENCES council_session (id) ON
            UPDATE
              NO ACTION ON DELETE NO ACTION
        SQL);
        $this->addSql('CREATE INDEX IDX_7CC7DA2C2395FCED ON video (thematic_id)');
        $this->addSql('CREATE INDEX IDX_7CC7DA2CE8327BBB ON video (council_session_id)');

        $this->addSql('ALTER TABLE subject DROP FOREIGN KEY FK_FBCE3E7AE8327BBB');
        $this->addSql('ALTER TABLE subject DROP FOREIGN KEY FK_FBCE3E7A2395FCED');
        $this->addSql('DROP TABLE subject');
    }
}
