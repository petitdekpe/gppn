<?php

namespace App\Service;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Vignette WebP d'une couverture, pour les cartes : réduite à la largeur
 * d'une carte en haute densité, dans thumbs/ du même stockage. L'image
 * d'origine reste pour la fiche détail et les aperçus de partage (og:image),
 * que certains réseaux sociaux lisent mal en WebP.
 *
 * Créée au dépôt d'une couverture (CoverThumbnailSubscriber, Paramètres) ;
 * app:covers:thumbnails rattrape celles qui manquent.
 */
class CoverThumbnailer
{
    private const DIR = 'thumbs/';
    private const MAX_WIDTH = 800;
    private const QUALITY = 78;

    public function __construct(
        #[Autowire(service: 'video_cover.storage')]
        private readonly FilesystemOperator $storage,
    ) {
    }

    public static function path(string $fileName): string
    {
        return self::DIR . pathinfo($fileName, PATHINFO_FILENAME) . '.webp';
    }

    public function exists(string $fileName): bool
    {
        try {
            return $this->storage->fileExists(self::path($fileName));
        } catch (FilesystemException) {
            return false;
        }
    }

    /** URL publique de la vignette, ou null si elle n'a pas (encore) été créée. */
    public function publicUrl(string $fileName): ?string
    {
        return $this->exists($fileName) ? $this->storage->publicUrl(self::path($fileName)) : null;
    }

    /**
     * @return bool false si l'image d'origine est illisible (format non pris en charge par GD, fichier absent)
     */
    public function generate(string $fileName): bool
    {
        try {
            $source = @imagecreatefromstring($this->storage->read($fileName));
        } catch (FilesystemException) {
            return false;
        }
        if ($source === false) {
            return false;
        }

        $image = imagesx($source) > self::MAX_WIDTH ? imagescale($source, self::MAX_WIDTH, -1, IMG_BICUBIC) : $source;
        imagepalettetotruecolor($image);
        imagesavealpha($image, true);

        ob_start();
        $encoded = imagewebp($image, null, self::QUALITY);
        $webp = ob_get_clean();
        if (!$encoded || $webp === '' || $webp === false) {
            return false;
        }

        $this->storage->write(self::path($fileName), $webp);

        return true;
    }

    public function delete(string $fileName): void
    {
        try {
            $this->storage->delete(self::path($fileName));
        } catch (FilesystemException) {
            // Vignette orpheline sans conséquence : plus aucune couverture ne la désigne.
        }
    }
}
