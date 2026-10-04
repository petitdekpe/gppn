<?php

namespace App\Enum;

/**
 * Formats proposés sur le site public : Vidéo TV, Vidéo Mobile et Audio MP3
 * (plus PDF et image). Sert aux filtres de recherche, aux badges, aux lots
 * de l'espace média et aux types activés dans les Paramètres. La nature du
 * fichier, pour choisir le lecteur, relève de VideoFileType::getMedium().
 */
enum CapsuleFormat: string
{
    case TV = 'tv';
    case MOBILE = 'mobile';
    case AUDIO = 'audio';
    case PDF = 'pdf';
    case IMAGE = 'image';

    /**
     * Ancienne valeur unique des vidéos, avant la distinction TV / Mobile :
     * peut encore figurer dans les Paramètres enregistrés.
     */
    public const LEGACY_VIDEO = 'video';

    public function getLabel(): string
    {
        return match ($this) {
            self::TV => 'Vidéo TV',
            self::MOBILE => 'Vidéo Mobile',
            self::AUDIO => 'Audio MP3',
            self::PDF => 'PDF',
            self::IMAGE => 'Image',
        };
    }

    public function isVideo(): bool
    {
        return $this === self::TV || $this === self::MOBILE;
    }

    /**
     * Types de fichiers (VideoFile) qui relèvent de ce format — utilisé
     * pour filtrer les contenus par « format » sur le site public à partir
     * des fichiers réellement déposés, un contenu n'ayant plus de format
     * unique déclaré.
     *
     * @return VideoFileType[]
     */
    public function getVideoFileTypes(): array
    {
        return match ($this) {
            self::TV => [VideoFileType::MP4_1080P, VideoFileType::MP4_480P],
            self::MOBILE => [VideoFileType::MP4_VERTICAL],
            self::AUDIO => [VideoFileType::AUDIO],
            self::PDF => [VideoFileType::PDF],
            self::IMAGE => [VideoFileType::IMAGE],
        };
    }
}
