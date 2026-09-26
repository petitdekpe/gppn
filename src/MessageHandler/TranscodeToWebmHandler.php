<?php

namespace App\MessageHandler;

use App\Entity\VideoFile;
use App\Enum\WebmStatus;
use App\Message\TranscodeToWebm;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;

/**
 * Génère avec ffmpeg la version WebM (VP9 + Opus) d'une vidéo TV/Mobile.
 * C'est elle que lit le site ; l'original reste le fichier téléchargeable.
 */
#[AsMessageHandler]
final class TranscodeToWebmHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'stream.storage')]
        private readonly FilesystemOperator $streamStorage,
        #[Autowire('%app.downloads_dir%')]
        private readonly string $downloadsDir,
        #[Autowire('%env(FFMPEG_BINARY)%')]
        private readonly string $ffmpegBinary,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(TranscodeToWebm $message): void
    {
        $file = $this->entityManager->find(VideoFile::class, $message->videoFileId);
        $sourceFileName = $file?->getFileName();
        if ($sourceFileName === null || !$file->getType()->hasWebmPlayback()) {
            return;
        }

        $file->setWebmStatus(WebmStatus::PROCESSING);
        $this->entityManager->flush();

        $output = tempnam(sys_get_temp_dir(), 'webm_');

        try {
            $process = new Process([
                $this->ffmpegBinary, '-hide_banner', '-nostdin', '-y',
                '-i', $this->downloadsDir . '/' . $sourceFileName,
                '-map', '0:v:0', '-map', '0:a:0?',
                // Qualité contrainte : sans plafond (-b:v 0), le WebM dépassait
                // la taille des MP4 d'origine déjà très compressés.
                '-c:v', 'libvpx-vp9', '-crf', '34', '-b:v', '2M',
                '-deadline', 'good', '-cpu-used', '5', '-row-mt', '1',
                '-pix_fmt', 'yuv420p',
                '-c:a', 'libopus', '-b:a', '96k',
                '-f', 'webm', $output,
            ]);
            $process->setTimeout(null);
            $process->run();

            // Le fichier d'origine a pu être remplacé ou supprimé pendant la
            // conversion : ce résultat est alors obsolète.
            $this->entityManager->refresh($file);
            if ($file->getFileName() !== $sourceFileName) {
                return;
            }

            if (!$process->isSuccessful()) {
                $this->logger->error('Échec de la conversion WebM du fichier {id} : {error}', [
                    'id' => $file->getId(),
                    'error' => mb_substr($process->getErrorOutput(), -2000),
                ]);
                $file->setWebmStatus(WebmStatus::FAILED);
                $this->entityManager->flush();

                return;
            }

            $webmFileName = pathinfo($sourceFileName, PATHINFO_FILENAME) . '.webm';
            $stream = fopen($output, 'rb');
            try {
                $this->streamStorage->writeStream($webmFileName, $stream);
            } finally {
                fclose($stream);
            }

            $previous = $file->getWebmFileName();
            if ($previous !== null && $previous !== $webmFileName) {
                $this->streamStorage->delete($previous);
            }

            $file
                ->setWebmFileName($webmFileName)
                ->setWebmFileSize(filesize($output) ?: null)
                ->setWebmStatus(WebmStatus::READY);
            $this->entityManager->flush();
        } finally {
            @unlink($output);
        }
    }
}
