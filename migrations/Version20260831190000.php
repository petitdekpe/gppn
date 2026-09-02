<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute les membres du Gouvernement et les Ministres Conseillers à la Présidence
 * comme intervenants (avec leur titre en fonction).
 */
final class Version20260831190000 extends AbstractMigration
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    private const SPEAKERS = [
        // Cabinet Ministériel
        ['Yvon DÉTCHÉNOU', 'Garde des Sceaux, Ministre de la Justice et de la Législation'],
        ['Aristide MÈDÉNOU', 'Ministre de l’Économie et des Finances, chargé de la Coopération'],
        ['Corinne AMORI BRUNET', 'Ministre des Affaires Étrangères, chargé de l’intégration des afrodescendants'],
        ['Djibril MAMA CISSÉ MOUSSA', 'Ministre délégué auprès du Président de la République, chargé de l’Intérieur et de la Sécurité Publique'],
        ['Gildas AGONKAN', 'Ministre délégué auprès du Président de la République, chargé de la Défense Nationale'],
        ['Olushegun ADJADI BAKARI', 'Ministre du Tourisme et du Commerce Extérieur, en charge de l’Industrie et de la Promotion de l’investissement privé'],
        ['Adin Yaton BLOUKOUNON GOUBALAN', 'Ministre de l’Agriculture, de l’Élevage et de la Pêche'],
        ['Benjamin Ignace Bodounrin HOUNKPATIN', 'Ministre de la Santé'],
        ['Sèdami MÈDÉGAN FAGLA', 'Ministre de l’Enseignement Supérieur et de la Recherche Scientifique, en charge de la Formation Technique'],
        ['Clément KOUCHADÉ', 'Ministre de l’Enseignement Secondaire'],
        ['Armand Kuyema NATTA', 'Ministre des Enseignements Maternel et Primaire'],
        ['Véronique TOGNIFODÉ', 'Ministre de la Famille et de l’Action Sociale'],
        ['Janvier YAHOUÉDÉOU', 'Ministre de la Décentralisation et de la Gouvernance Locale'],
        ['Yassine LATOUNDJI', 'Ministre de la Culture, des Arts et du Patrimoine'],
        ['Shadiya Alimatou ASSOUMAN', 'Ministre du Commerce Intérieur, en charge de la formalisation de l’économie'],
        ['Mahuna AKPLOGAN', 'Ministre de la Transformation Digitale et de l’Innovation, en charge de la stratégie nationale d’IA'],
        ['Édouard DAHOMÉ', 'Ministre de l’Énergie, de l’Eau et des Mines'],
        ['Georges ALÉ', 'Ministre du Cadre de Vie et des Transports, chargé du Développement Durable'],
        ['Awaou BACO', 'Ministre des PME et de la Promotion de l’Emploi, en charge de la Formation Professionnelle'],
        ['Aurélie ADAM SOULÉ épouse ZOUMAROU', 'Ministre de la Communication, en charge des Médias'],
        ['Benoît K. M. DATO', 'Ministre des Sports et de l’Engagement Civique'],
        ['Nicolas YÉNOUSSI', 'Ministre délégué auprès du MEF, chargé des finances et de la microfinance'],
        ['Rodrigue CHAOU', 'Ministre délégué auprès du MEF, chargé du budget et de la fonction publique'],
        ['Hugues Oscar LOKOSSOU', 'Ministre délégué auprès du MEF, chargé de la mobilisation des ressources extérieures et de la gestion de la dette'],

        // Collège des Ministres Conseillers à la Présidence de la République
        ['Jeanne ADANBIOKOU AKAKPO', 'Ministre Conseillère à la Présidence, chargée des Infrastructures et du Cadre de Vie (Coordinatrice du Collège)'],
        ['Comlan Patrice NOMBIME AGBODRANFO', 'Ministre Conseiller à la Présidence, chargé des Affaires Économiques'],
        ['Eudoxie DAKPÉ', 'Ministre Conseillère à la Présidence, chargée de la Justice et des Relations Extérieures'],
        ['Bio Guéra SACCA KINA', 'Ministre Conseiller à la Présidence, chargé de l’Agriculture'],
        ['Mariam DJAOUGA SACCA', 'Ministre Conseillère à la Présidence, chargée de la Famille et de l’Action Sociale'],
        ['Ayibatin Jonas HANTAN', 'Ministre Conseiller à la Présidence, chargé des Sports, de la Culture, des Arts et de la Chefferie Traditionnelle'],
        ['Rosine DAGNIHO', 'Ministre Conseillère à la Présidence, chargée de la Santé'],
        ['Rachidi GBADAMASSI', 'Ministre Conseiller à la Présidence, chargé de la Défense et de la Sécurité'],
        ['Romaric OGOUWALÉ', 'Ministre Conseiller à la Présidence, chargé de l’Énergie, de l’Eau et des Mines'],
        ['Nicaise Kotchami FAGNON', 'Ministre Conseiller à la Présidence, chargé des PME, de la Promotion de l’Emploi et de la Formation Professionnelle'],
        ['Paulin GBÉNOU', 'Ministre Conseiller à la Présidence, chargé des Enseignements Maternel et Primaire'],
        ['Mahamadou DAHOUDA', 'Ministre Conseiller à la Présidence, chargé de l’Enseignement Supérieur, de la Recherche Scientifique et de la Formation Technique'],
    ];

    public function getDescription(): string
    {
        return 'Ajoute les membres du Gouvernement et les Ministres Conseillers à la Présidence comme intervenants.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::SPEAKERS as [$fullName, $role]) {
            $this->addSql('INSERT INTO speaker (full_name, role) VALUES (?, ?)', [$fullName, $role]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::SPEAKERS as [$fullName, $role]) {
            $this->addSql('DELETE FROM speaker WHERE full_name = ? AND role = ?', [$fullName, $role]);
        }
    }
}
