<?php

namespace App\Service;

use App\Entity\VideoFile;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class VideoFileZipBuilder
{
    public function __construct(
        #[Autowire(service: 'download.storage')]
        private readonly FilesystemOperator $storage,
    ) {
    }

    /**
     * Construit une archive ZIP temporaire à partir des fichiers fournis et
     * retourne son chemin local. L'appelant est responsable de supprimer ce
     * fichier une fois la réponse envoyée (ex : `BinaryFileResponse::deleteFileAfterSend(true)`).
     *
     * @param VideoFile[] $files
     */
    public function build(array $files): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'gppn_zip_') . '.zip';

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $localCopies = [];
        $usedEntryNames = [];

        try {
            foreach ($files as $file) {
                $fileName = $file->getFileName();
                if ($fileName === null) {
                    continue;
                }

                $stream = $this->storage->readStream($fileName);
                $localCopy = tempnam(sys_get_temp_dir(), 'gppn_zip_src_');
                $localCopies[] = $localCopy;

                $destination = fopen($localCopy, 'wb');
                stream_copy_to_stream($stream, $destination);
                fclose($destination);
                fclose($stream);

                $entryName = $this->uniqueEntryName($this->buildEntryName($file), $usedEntryNames);

                $zip->addFile($localCopy, $entryName);
            }
        } finally {
            $zip->close();

            foreach ($localCopies as $localCopy) {
                @unlink($localCopy);
            }
        }

        return $zipPath;
    }

    /**
     * Nom d'entrée lisible même quand plusieurs variantes de plusieurs
     * contenus se retrouvent mélangées dans une même archive : slug de la
     * vidéo + libellé du type de fichier.
     */
    private function buildEntryName(VideoFile $file): string
    {
        $originalName = $file->getOriginalName() ?? $file->getFileName();
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $type = $file->getType();
        $slug = $file->getVideo()?->getSlug() ?? 'contenu';
        $typeSlug = $type !== null ? preg_replace('/^\d+_/', '', $type->value) : 'fichier';

        return $extension !== '' ? sprintf('%s-%s.%s', $slug, $typeSlug, $extension) : sprintf('%s-%s', $slug, $typeSlug);
    }

    /**
     * @param array<string, true> $usedEntryNames
     */
    private function uniqueEntryName(string $entryName, array &$usedEntryNames): string
    {
        $base = $entryName;
        $suffix = 1;

        while (isset($usedEntryNames[$entryName])) {
            $extension = pathinfo($base, PATHINFO_EXTENSION);
            $baseName = pathinfo($base, PATHINFO_FILENAME);
            $entryName = $extension !== '' ? sprintf('%s-%d.%s', $baseName, $suffix, $extension) : sprintf('%s-%d', $baseName, $suffix);
            ++$suffix;
        }

        $usedEntryNames[$entryName] = true;

        return $entryName;
    }
}
