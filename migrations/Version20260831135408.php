<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260831135408 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE speaker (id INT AUTO_INCREMENT NOT NULL, full_name VARCHAR(150) NOT NULL, role VARCHAR(150) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('CREATE TABLE video_speaker (video_id INT NOT NULL, speaker_id INT NOT NULL, INDEX IDX_9E6832CC29C1004E (video_id), INDEX IDX_9E6832CCD04A0F27 (speaker_id), PRIMARY KEY (video_id, speaker_id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE video_speaker ADD CONSTRAINT FK_9E6832CC29C1004E FOREIGN KEY (video_id) REFERENCES video (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE video_speaker ADD CONSTRAINT FK_9E6832CCD04A0F27 FOREIGN KEY (speaker_id) REFERENCES speaker (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE video_speaker DROP FOREIGN KEY FK_9E6832CC29C1004E');
        $this->addSql('ALTER TABLE video_speaker DROP FOREIGN KEY FK_9E6832CCD04A0F27');
        $this->addSql('DROP TABLE speaker');
        $this->addSql('DROP TABLE video_speaker');
    }
}
