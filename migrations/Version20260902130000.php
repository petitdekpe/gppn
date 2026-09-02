<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Langues nationales du Bénin utilisées par le programme (voir le filtre
 * « Langue » sur le site public et dans l'admin des contenus).
 */
final class Version20260902130000 extends AbstractMigration
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    private const LANGUAGES = [
        ['Fon', 'fon'],
        ['Yoruba', 'yoruba'],
        ['Dendi', 'dendi'],
        ['Goun', 'goun'],
        ['Ditamari', 'ditamari'],
        ['Baatonou', 'baatonou'],
        ['Adja', 'adja'],
        ['Mahi', 'mahi'],
        ['Idatcha', 'idatcha'],
        ['Tori', 'tori'],
        ['Kotafon', 'kotafon'],
        ['Fulfulde', 'fulfulde'],
        ['Sahoué', 'sahoue'],
        ['Waama', 'waama'],
    ];

    public function getDescription(): string
    {
        return 'Ajoute les langues nationales du Bénin utilisées par le programme.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::LANGUAGES as [$name, $slug]) {
            $this->addSql('INSERT INTO language (name, slug) VALUES (?, ?)', [$name, $slug]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::LANGUAGES as [$name, $slug]) {
            $this->addSql('DELETE FROM language WHERE slug = ?', [$slug]);
        }
    }
}
