<?php

namespace App\Service;

use Symfony\Component\String\UnicodeString;

/**
 * Devine un sigle de ministère (ex. « MEF ») à partir du champ libre « rôle »
 * d'un intervenant (ex. « Ministre de l'Économie et des Finances, chargé de
 * la Coopération » → MEF). Résultat indicatif : la formulation des rôles
 * n'est pas assez régulière pour garantir un sigle officiel à chaque fois,
 * le champ reste modifiable à la main dans le formulaire.
 */
final class SpeakerSigleGuesser
{
    private const STOPWORDS = ['de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'et', 'a', 'en', 'au', 'aux'];

    public function guess(?string $role): ?string
    {
        if ($role === null || trim($role) === '') {
            return null;
        }

        $domain = $this->extractDomain($role);
        if ($domain === null || trim($domain) === '') {
            return null;
        }

        $initials = [];
        foreach (preg_split('/[\s\'’]+/u', $domain, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            // Un mot déjà tout en majuscules (ex. « PME », « ANIP ») est un
            // sigle présent tel quel dans le texte libre : on le garde en
            // entier plutôt que de n'en prendre que l'initiale.
            if (preg_match('/^\p{Lu}{2,6}$/u', $word) === 1) {
                $initials[] = (new UnicodeString($word))->ascii()->toString();

                continue;
            }

            $ascii = strtolower((new UnicodeString($word))->ascii()->toString());
            $letters = preg_replace('/[^a-z]/', '', $ascii);
            if ($letters === '' || in_array($letters, self::STOPWORDS, true)) {
                continue;
            }
            $initials[] = strtoupper($letters[0]);
        }

        return $initials === [] ? null : 'M' . implode('', $initials);
    }

    /**
     * Isole le nom du portefeuille dans le rôle libre :
     * - un ministre « classique » : ce qui suit « Ministre de/du/des » ;
     * - un ministre délégué/conseiller : le début de phrase (« délégué
     *   auprès du Président de la République », « Conseiller à la
     *   Présidence ») est générique, le vrai portefeuille suit « chargé(e)
     *   de/du/des ».
     * Dans les deux cas, une précision annexe (« chargé de... », « en charge
     * de... », une parenthèse finale) arrête la capture : elle ne fait pas
     * partie du nom du ministère — contrairement à une simple virgule, qui
     * peut faire partie du nom lui-même (« Agriculture, de l'Élevage et de
     * la Pêche »).
     */
    private function extractDomain(string $role): ?string
    {
        $prefix = '(?:de\s+l[\'’]|de\s+la\s+|du\s+|des\s+|de\s+)?';

        if (str_contains($role, 'Conseill') || preg_match('/délégué|déléguée/ui', $role) === 1) {
            if (preg_match('/charg[ée]e?\s+' . $prefix . '(.+)/ui', $role, $matches) !== 1) {
                return null;
            }
        } elseif (preg_match('/Ministre\s+' . $prefix . '(.+)/ui', $role, $matches) !== 1) {
            return null;
        }

        $domain = preg_split('/,?\s*(?:en\s+)?charg[ée]e?\s+/ui', $matches[1], 2)[0];

        return preg_split('/\s*\(/u', $domain, 2)[0];
    }
}
