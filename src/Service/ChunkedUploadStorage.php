<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Fichiers reçus par morceaux (import en masse). Chaque envoi s'accumule dans
 * un fichier partiel dont la taille fait foi : le navigateur la demande avant
 * de commencer, ce qui permet de reprendre après une coupure ou un
 * rechargement de page sans renvoyer ce qui est déjà arrivé.
 *
 * Les partiels vivent sur le disque local du serveur applicatif, pas sur le
 * partage NFS : seul le fichier complet y est copié, par VichUploader.
 */
class ChunkedUploadStorage
{
    /** Partiel abandonné depuis plus longtemps : supprimé au prochain passage. */
    private const STALE_AFTER = 172800;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/import-chunks')]
        private readonly string $directory,
    ) {
    }

    /**
     * Identifiant d'envoi : calculé par le navigateur (nom, taille, date du
     * fichier), rattaché ici au sujet et à l'utilisateur pour qu'un envoi ne
     * puisse pas reprendre celui d'un autre.
     */
    public static function isValidId(string $uploadId): bool
    {
        return preg_match('/^[a-z0-9-]{8,80}$/', $uploadId) === 1;
    }

    public function offset(string $key): int
    {
        clearstatcache(true, $this->path($key));

        return is_file($this->path($key)) ? (int) filesize($this->path($key)) : 0;
    }

    /**
     * Ajoute un morceau à la position attendue. Si la position ne correspond
     * pas (morceau déjà reçu, renvoyé après un délai dépassé), rien n'est écrit
     * et la position réelle est renvoyée pour que le navigateur s'y recale.
     *
     * @param resource $input
     *
     * @return array{accepted: bool, offset: int}
     */
    public function append(string $key, int $offset, int $length, $input): array
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException(sprintf('Impossible de créer le dossier des envois partiels (%s).', $this->directory));
        }

        $handle = fopen($this->path($key), 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Impossible d’ouvrir le fichier partiel sur le serveur.');
        }

        try {
            // Un morceau renvoyé peut arriver pendant que le précédent s'écrit.
            flock($handle, \LOCK_EX);
            $size = fstat($handle)['size'];
            if ($size !== $offset) {
                return ['accepted' => false, 'offset' => $size];
            }

            fseek($handle, $size);
            $written = stream_copy_to_stream($input, $handle);
            fflush($handle);

            if ($written !== $length) {
                // Morceau tronqué : on revient à l'état d'avant pour qu'il soit renvoyé en entier.
                ftruncate($handle, $size);
                throw new \RuntimeException(sprintf('Morceau incomplet : %d octets reçus sur %d (disque plein ou connexion coupée).', max(0, (int) $written), $length));
            }

            return ['accepted' => true, 'offset' => $size + $written];
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Fichier complet, présenté comme un upload classique à la validation et
     * à VichUploader (mode test : il ne vient pas de move_uploaded_file).
     */
    public function file(string $key, string $originalName): UploadedFile
    {
        return new UploadedFile($this->path($key), $originalName, null, \UPLOAD_ERR_OK, true);
    }

    public function remove(string $key): void
    {
        @unlink($this->path($key));
    }

    public function purgeStale(): void
    {
        foreach (glob($this->directory . '/*.part') ?: [] as $path) {
            if (@filemtime($path) < time() - self::STALE_AFTER) {
                @unlink($path);
            }
        }
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . $key . '.part';
    }
}
