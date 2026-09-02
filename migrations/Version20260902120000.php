<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Introduit les conseils des ministres comme source obligatoire des lots de
 * vidéos : chaque capsule doit désormais être rattachée à un conseil des
 * ministres identifié par une date. Les vidéos existantes sont rattachées
 * rétroactivement à un conseil créé pour leur date de publication.
 */
final class Version20260902120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la table council_session et rattache video.council_session_id (obligatoire).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE council_session (id INT AUTO_INCREMENT NOT NULL, date DATE NOT NULL, label VARCHAR(200) DEFAULT NULL, slug VARCHAR(220) NOT NULL, UNIQUE INDEX UNIQ_A7DC21D0AA9E377A (date), UNIQUE INDEX UNIQ_A7DC21D0989D9B62 (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');

        $this->addSql('ALTER TABLE video ADD council_session_id INT DEFAULT NULL');

        $this->addSql('INSERT INTO council_session (date, slug) SELECT DISTINCT DATE(v.published_at), CONCAT(\'conseil-du-\', DATE_FORMAT(v.published_at, \'%Y-%m-%d\')) FROM video v');
        $this->addSql('UPDATE video v INNER JOIN council_session cs ON cs.date = DATE(v.published_at) SET v.council_session_id = cs.id');

        $this->addSql('ALTER TABLE video CHANGE council_session_id council_session_id INT NOT NULL');
        $this->addSql('ALTER TABLE video ADD CONSTRAINT FK_7CC7DA2CD9AAD86D FOREIGN KEY (council_session_id) REFERENCES council_session (id)');
        $this->addSql('CREATE INDEX IDX_7CC7DA2CE8327BBB ON video (council_session_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video DROP FOREIGN KEY FK_7CC7DA2CD9AAD86D');
        $this->addSql('DROP INDEX IDX_7CC7DA2CE8327BBB ON video');
        $this->addSql('ALTER TABLE video DROP council_session_id');

        $this->addSql('DROP TABLE council_session');
    }
}
