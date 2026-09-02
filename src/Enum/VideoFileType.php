<?php

namespace App\Enum;

/**
 * Les valeurs sont préfixées `1_`…`6_` exprès : un `ORDER BY type ASC` donne
 * directement l'ordre d'affichage voulu, sans logique de tri dédiée (voir
 * Video::getFiles()).
 */
enum VideoFileType: string
{
    case MP4_1080P = '1_mp4_1080p';
    case MP4_480P = '2_mp4_480p';
    case MP4_VERTICAL = '3_mp4_vertical';
    case AUDIO = '4_audio';
    case PDF = '5_pdf';
    case IMAGE = '6_image';

    public function getLabel(): string
    {
        return match ($this) {
            self::MP4_1080P => 'Vidéo HD 1080p',
            self::MP4_480P => 'Vidéo allégée 480p',
            self::MP4_VERTICAL => 'Format vertical',
            self::AUDIO => 'Version audio',
            self::PDF => 'Fiche PDF',
            self::IMAGE => 'Image',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::MP4_1080P => 'Pour la télévision et les écrans publics',
            self::MP4_480P => 'Pour un partage en zone à faible débit',
            self::MP4_VERTICAL => 'Stories, statuts WhatsApp, Facebook…',
            self::AUDIO => 'Prête à être diffusée dans les groupes et les radios communautaires',
            self::PDF => 'Imprimable, partageable via WhatsApp',
            self::IMAGE => 'Partageable via WhatsApp, Facebook',
        };
    }

    public function getBadge(): string
    {
        return match ($this) {
            self::MP4_1080P, self::MP4_480P => 'MP4',
            self::MP4_VERTICAL => '9:16',
            self::AUDIO => 'AUDIO',
            self::PDF => 'PDF',
            self::IMAGE => 'IMAGE',
        };
    }

    public function isVideo(): bool
    {
        return in_array($this, [self::MP4_1080P, self::MP4_480P, self::MP4_VERTICAL], true);
    }

    public function isAudio(): bool
    {
        return $this === self::AUDIO;
    }

    public function isPdf(): bool
    {
        return $this === self::PDF;
    }

    public function isImage(): bool
    {
        return $this === self::IMAGE;
    }

    public function getCategory(): CapsuleFormat
    {
        return match (true) {
            $this->isVideo() => CapsuleFormat::VIDEO,
            $this->isAudio() => CapsuleFormat::AUDIO,
            $this->isPdf() => CapsuleFormat::PDF,
            $this->isImage() => CapsuleFormat::IMAGE,
        };
    }
}
