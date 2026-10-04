<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Poids de fichier à la française : |file_size → « 155,5 Mo », « 850 Ko », « 1,2 Go ».
 * Virgule décimale, espace insécable avant l'unité (pas de retour à la ligne entre les deux).
 */
class FileSizeExtension extends AbstractExtension
{
    private const KB = 1024;
    private const MB = 1024 ** 2;
    private const GB = 1024 ** 3;

    public function getFilters(): array
    {
        return [
            new TwigFilter('file_size', self::format(...)),
        ];
    }

    public static function format(int|float|null $bytes): string
    {
        $bytes = (float) $bytes;

        return match (true) {
            $bytes >= self::GB => self::number($bytes / self::GB, 1) . "\u{00A0}Go",
            $bytes >= self::MB => self::number($bytes / self::MB, 1) . "\u{00A0}Mo",
            default => self::number(max(1, $bytes / self::KB), 0) . "\u{00A0}Ko",
        };
    }

    /** « 155,5 », « 12 » plutôt que « 12,0 », milliers séparés par une espace fine insécable. */
    private static function number(float $value, int $decimals): string
    {
        $formatted = number_format(round($value, $decimals), $decimals, ',', "\u{202F}");

        return $decimals > 0 ? preg_replace('/,0+$/', '', $formatted) : $formatted;
    }
}
