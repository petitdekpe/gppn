<?php

namespace App\Util;

/**
 * Dates et heures affichées sur le site, en français : « 1er juin 2026 »,
 * « 17 juin 2026 à 14 h 30 ». Sans dépendre de la locale du serveur.
 */
final class FrenchDate
{
    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    private const SHORT_MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    public static function date(\DateTimeInterface $date): string
    {
        $day = (int) $date->format('j');

        return sprintf('%s %s %s', $day === 1 ? '1er' : $day, self::MONTHS[(int) $date->format('n') - 1], $date->format('Y'));
    }

    /** Mois abrégé, pour les libellés courts (filtres) : « 9 sept. 2026 », « 1er août 2026 ». */
    public static function shortDate(\DateTimeInterface $date): string
    {
        $day = (int) $date->format('j');

        return sprintf('%s %s %s', $day === 1 ? '1er' : $day, self::SHORT_MONTHS[(int) $date->format('n') - 1], $date->format('Y'));
    }

    public static function time(\DateTimeInterface $date): string
    {
        return $date->format('G \h i');
    }

    public static function dateTime(\DateTimeInterface $date): string
    {
        return self::date($date) . ' à ' . self::time($date);
    }
}
