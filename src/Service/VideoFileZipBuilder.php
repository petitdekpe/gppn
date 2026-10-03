<?php

namespace App\Service;

use App\Entity\VideoFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class VideoFileZipBuilder
{
    /**
     * Les fichiers sont lus directement dans le répertoire de download.storage
     * (partage NFS en prod) plutôt que recopiés dans /tmp : chaque vidéo ne
     * transite qu'une fois sur le réseau et le disque local n'accueille que
     * l'archive finale.
     */
    public function __construct(
        #[Autowire(param: 'app.downloads_dir')]
        private readonly string $downloadsDir,
    ) {
    }

    /**
     * Construit une archive ZIP temporaire à partir des fichiers fournis et
     * retourne son chemin local. L'appelant est responsable de supprimer ce
     * fichier une fois la réponse envoyée (ex : `BinaryFileResponse::deleteFileAfterSend(true)`).
     *
     * @param VideoFile[] $files
     * @param string|null $attributionSheet Contenu texte d'une fiche d'attribution
     *   ajoutée à la racine de l'archive (constructeur de lot de l'espace média).
     * @param (\Closure(VideoFile): ?string)|null $folderFor dossier de chaque
     *   fichier dans l'archive (ex. « TV », « Radio », « Mobile » pour un kit)
     */
    public function build(array $files, ?string $attributionSheet = null, ?\Closure $folderFor = null): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'gppn_zip_') . '.zip';

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $usedEntryNames = [];

        try {
            foreach ($files as $file) {
                $fileName = $file->getFileName();
                if ($fileName === null) {
                    continue;
                }

                $sourcePath = $this->downloadsDir . '/' . $fileName;
                if (!is_file($sourcePath)) {
                    throw new \RuntimeException(sprintf('Fichier introuvable dans le stockage : "%s".', $fileName));
                }

                $folder = $folderFor !== null ? $folderFor($file) : null;
                $entryName = $this->uniqueEntryName(($folder !== null ? $folder . '/' : '') . $this->buildEntryName($file), $usedEntryNames);

                $zip->addFile($sourcePath, $entryName);
                // Vidéos et images sont déjà compressées : les stocker telles
                // quelles évite de mobiliser le CPU pour un gain nul.
                $zip->setCompressionName($entryName, \ZipArchive::CM_STORE);
            }

            if ($attributionSheet !== null) {
                $zip->addFromString('fiche-attribution.txt', $attributionSheet);
            }
        } finally {
            $zip->close();
        }

        return $zipPath;
    }

    /**
     * Fiche d'attribution jointe aux lots (espace média) et aux kits
     * (page intervenant) : liste des contenus et mention obligatoire.
     *
     * @param VideoFile[] $files
     */
    public function attributionSheet(array $files, ?string $title = null): string
    {
        $lines = [
            'LE GOUVERNEMENT PLUS PRÈS DE NOUS — FICHE D’ATTRIBUTION',
            ...($title !== null ? [$title] : []),
            'Lot généré le ' . (new \DateTimeImmutable())->format('d/m/Y à H:i'),
            '',
            'Contenu de ce lot :',
        ];

        foreach ($files as $file) {
            $video = $file->getVideo();
            $lines[] = sprintf(
                '- %s | %s | %s | %s',
                $video->getTitle(),
                $video->getThematic()->getName(),
                $video->getLanguage()->getName(),
                $file->getType()->getLabel(),
            );
        }

        $lines[] = '';
        $lines[] = 'Mention obligatoire à la diffusion : « Le Gouvernement Plus Près de Nous - République du Bénin. »';

        return implode("\n", $lines);
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
            // Dossier conservé (kits rangés en TV/, Radio/, Mobile/).
            $directory = str_contains($base, '/') ? substr($base, 0, strrpos($base, '/') + 1) : '';
            $baseName = $directory . pathinfo($base, PATHINFO_FILENAME);
            $entryName = $extension !== '' ? sprintf('%s-%d.%s', $baseName, $suffix, $extension) : sprintf('%s-%d', $baseName, $suffix);
            ++$suffix;
        }

        $usedEntryNames[$entryName] = true;

        return $entryName;
    }
}
