<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Couvertures tirées des vidéos avant la correction de Video::setCoverImageFile :
 * VichUploader y réinjectait le fichier enregistré, ce qui remettait
 * cover_generated à 0. Elles se reconnaissent au nom donné par
 * VideoCoverGenerator (« couverture-<slug>-<seconde>s-<identifiant>.jpg »).
 */
final class Version20261003100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rétablit cover_generated pour les couvertures tirées des vidéos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE video SET cover_generated = 1 WHERE cover_generated = 0 AND cover_image_name REGEXP '^couverture-.+-[0-9]+s-[0-9a-f]+\\\\.jpg$'");
    }

    public function down(Schema $schema): void
    {
        // Rien à défaire : l'indicateur reflète désormais l'origine réelle de l'image.
    }
}
