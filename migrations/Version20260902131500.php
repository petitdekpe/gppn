<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Thématiques du programme (voir le filtre « Thématique » sur le site public
 * et dans l'admin des contenus).
 */
final class Version20260902131500 extends AbstractMigration
{
    /**
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const THEMATICS = [
        ['État civil', 'etat-civil', '#1F7A54'],
        ['Santé', 'sante', '#C9932A'],
        ['Agriculture', 'agriculture', '#2F6F93'],
        ['Numérique', 'numerique', '#8A4B8F'],
        ['Éducation', 'education', '#4C7A2E'],
        ['Énergie', 'energie', '#B3541F'],
        ['Eau & assainissement', 'eau-assainissement', '#1F7A94'],
        ['Emploi & entrepreneuriat', 'emploi-entrepreneuriat', '#9C3550'],
        ['Protection sociale', 'protection-sociale', '#2D5B8A'],
        ['Transport', 'transport', '#7A6A1F'],
    ];

    public function getDescription(): string
    {
        return 'Ajoute les thématiques du programme.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::THEMATICS as [$name, $slug, $color]) {
            $this->addSql('INSERT INTO thematic (name, slug, color_hex) VALUES (?, ?, ?)', [$name, $slug, $color]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::THEMATICS as [$name, $slug]) {
            $this->addSql('DELETE FROM thematic WHERE slug = ?', [$slug]);
        }
    }
}
