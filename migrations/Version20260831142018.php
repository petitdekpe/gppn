<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260831142018 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE video_feedback (id INT AUTO_INCREMENT NOT NULL, rating VARCHAR(20) NOT NULL, comment LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, video_id INT NOT NULL, INDEX IDX_7AAB76E429C1004E (video_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB');
        $this->addSql('ALTER TABLE video_feedback ADD CONSTRAINT FK_7AAB76E429C1004E FOREIGN KEY (video_id) REFERENCES video (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE video ADD learning_points LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE video_feedback DROP FOREIGN KEY FK_7AAB76E429C1004E');
        $this->addSql('DROP TABLE video_feedback');
        $this->addSql('ALTER TABLE video DROP learning_points');
    }
}
