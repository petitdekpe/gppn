<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nouvelles langues du programme, et graphie corrigée d'Idatcha → Idaasha.
 * Bariba n'est pas ajoutée : c'est la même langue que Baatonou, déjà présente.
 */
final class Version20260926210000 extends AbstractMigration
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    private const LANGUAGES = [
        ['Nago', 'nago'],
        ['Mina', 'mina'],
        ['Djerma', 'djerma'],
        ['Ifè', 'ife'],
        ['Pédah', 'pedah'],
        ['Wémègbé', 'wemegbe'],
    ];

    public function getDescription(): string
    {
        return 'Ajoute les langues Nago, Mina, Djerma, Ifè, Pédah et Wémègbé ; renomme Idatcha en Idaasha.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::LANGUAGES as [$name, $slug]) {
            $this->addSql(
                'INSERT INTO language (name, slug) SELECT ?, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM language WHERE slug = ?)',
                [$name, $slug, $slug],
            );
        }

        $this->addSql("UPDATE language SET name = 'Idaasha', slug = 'idaasha' WHERE slug = 'idatcha'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE language SET name = 'Idatcha', slug = 'idatcha' WHERE slug = 'idaasha'");

        foreach (self::LANGUAGES as [, $slug]) {
            // Ne retire que les langues sans contenu, pour ne rien perdre.
            $this->addSql(
                'DELETE FROM language WHERE slug = ? AND NOT EXISTS (SELECT 1 FROM video WHERE video.language_id = language.id)',
                [$slug],
            );
        }
    }
}
