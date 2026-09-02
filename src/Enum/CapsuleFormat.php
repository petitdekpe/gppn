<?php

namespace App\Enum;

enum CapsuleFormat: string
{
    case VIDEO = 'video';
    case AUDIO = 'audio';
    case PDF = 'pdf';
    case IMAGE = 'image';

    public function getLabel(): string
    {
        return match ($this) {
            self::VIDEO => 'Vidéo',
            self::AUDIO => 'Audio',
            self::PDF => 'PDF',
            self::IMAGE => 'Image',
        };
    }

    /**
     * Types de fichiers (VideoFile) qui relèvent de cette catégorie — utilisé
     * pour filtrer les contenus par « format » sur le site public à partir
     * des fichiers réellement déposés, un contenu n'ayant plus de format
     * unique déclaré.
     *
     * @return VideoFileType[]
     */
    public function getVideoFileTypes(): array
    {
        return match ($this) {
            self::VIDEO => [VideoFileType::MP4_1080P, VideoFileType::MP4_480P, VideoFileType::MP4_VERTICAL],
            self::AUDIO => [VideoFileType::AUDIO],
            self::PDF => [VideoFileType::PDF],
            self::IMAGE => [VideoFileType::IMAGE],
        };
    }
}
