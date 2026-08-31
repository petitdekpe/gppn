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
}
