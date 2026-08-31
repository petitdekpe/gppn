<?php

namespace App\MessageHandler;

use App\Entity\Video;
use App\Enum\CapsuleFormat;
use App\Enum\VideoStatus;
use App\Message\ProcessVideoMessage;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessVideoHandler
{
    /**
     * Résolutions HLS générées, du bas vers le haut débit.
     *
     * @var array<string, array{width: int, height: int, kiloBitrate: int}>
     */
    private const HLS_RESOLUTIONS = [
        '360p' => ['width' => 640, 'height' => 360, 'kiloBitrate' => 800],
        '720p' => ['width' => 1280, 'height' => 720, 'kiloBitrate' => 2500],
    ];

    private const HLS_SEGMENT_SECONDS = 6;

    public function __construct(
        private readonly VideoRepository $videoRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $mediaPipelineLogger,
        #[Autowire('%kernel.project_dir%/public/uploads/videos')]
        private readonly string $storageDir,
        #[Autowire(env: 'FFMPEG_BINARY')]
        private readonly string $ffmpegBinary,
        #[Autowire(env: 'FFPROBE_BINARY')]
        private readonly string $ffprobeBinary,
        #[Autowire(env: 'bool:VIDEO_PIPELINE_AUTO_PUBLISH')]
        private readonly bool $autoPublish,
    ) {
    }

    public function __invoke(ProcessVideoMessage $message): void
    {
        $video = $this->videoRepository->find($message->videoId);

        if (!$video instanceof Video) {
            $this->mediaPipelineLogger->error('Vidéo introuvable, traitement annulé.', ['videoId' => $message->videoId]);

            return;
        }

        $this->mediaPipelineLogger->info('Début du traitement de la capsule.', [
            'videoId' => $video->getId(),
            'title' => $video->getTitle(),
            'format' => $video->getFormat()->value,
        ]);

        $video->setStatus(VideoStatus::EN_TRAITEMENT);
        $this->entityManager->flush();

        try {
            $sourcePath = $this->resolveSourcePath($video);

            if ($sourcePath !== null) {
                $this->hashSource($video, $sourcePath);
            }

            if ($sourcePath !== null && $video->getFormat() === CapsuleFormat::VIDEO) {
                $ffmpeg = FFMpeg::create([
                    'ffmpeg.binaries' => $this->ffmpegBinary,
                    'ffprobe.binaries' => $this->ffprobeBinary,
                    'timeout' => 3600,
                ], $this->mediaPipelineLogger);

                $this->generateThumbnail($video, $ffmpeg, $sourcePath);
                $this->readDuration($video, $ffmpeg, $sourcePath);
                $this->transcodeToHls($video, $ffmpeg, $sourcePath);
            }

            $video->setStatus($this->autoPublish ? VideoStatus::PUBLIE : VideoStatus::EN_RELECTURE);
            $this->entityManager->flush();

            $this->mediaPipelineLogger->info('Traitement terminé.', [
                'videoId' => $video->getId(),
                'status' => $video->getStatus()->value,
            ]);
        } catch (\Throwable $e) {
            $video->setStatus(VideoStatus::ECHEC);
            $this->entityManager->flush();

            $this->mediaPipelineLogger->error('Échec du traitement de la capsule.', [
                'videoId' => $video->getId(),
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Le stockage Vich est un adapter Flysystem local : on résout directement
     * le chemin disque réel plutôt que de passer par le flux Flysystem, ce que
     * FFmpeg (wrapper CLI) exige de toute façon. À revoir si le storage passe
     * un jour sur un adapter distant (S3…), qui nécessiterait un téléchargement
     * préalable vers un fichier temporaire local.
     */
    private function resolveSourcePath(Video $video): ?string
    {
        $fileName = $video->getSourceFileName();

        if ($fileName === null) {
            return null;
        }

        $path = $this->storageDir . '/' . $video->getSlug() . '/' . $fileName;

        return is_file($path) ? $path : null;
    }

    private function hashSource(Video $video, string $sourcePath): void
    {
        $video->setSourceSha256(hash_file('sha256', $sourcePath));
        $this->entityManager->flush();

        $this->mediaPipelineLogger->info('SHA-256 calculé.', [
            'videoId' => $video->getId(),
            'sha256' => $video->getSourceSha256(),
        ]);
    }

    private function generateThumbnail(Video $video, FFMpeg $ffmpeg, string $sourcePath): void
    {
        $relativePath = $video->getSlug() . '/thumbnail.jpg';
        $absolutePath = $this->storageDir . '/' . $relativePath;

        $ffmpeg->open($sourcePath)
            ->frame(TimeCode::fromSeconds(5))
            ->save($absolutePath);

        $video->setThumbnailPath($relativePath);
        $this->entityManager->flush();

        $this->mediaPipelineLogger->info('Vignette générée.', [
            'videoId' => $video->getId(),
            'path' => $relativePath,
        ]);
    }

    private function readDuration(Video $video, FFMpeg $ffmpeg, string $sourcePath): void
    {
        $duration = (float) $ffmpeg->getFFProbe()->format($sourcePath)->get('duration');
        $video->setDurationSeconds((int) round($duration));
        $this->entityManager->flush();

        $this->mediaPipelineLogger->info('Durée récupérée depuis FFprobe.', [
            'videoId' => $video->getId(),
            'durationSeconds' => $video->getDurationSeconds(),
        ]);
    }

    private function transcodeToHls(Video $video, FFMpeg $ffmpeg, string $sourcePath): void
    {
        $hlsRelativeDir = sprintf('hls/%d', $video->getId());
        $hlsAbsoluteDir = $this->storageDir . '/' . $hlsRelativeDir;

        /** @var list<array{label: string, bandwidth: int, resolution: string}> $variants */
        $variants = [];

        foreach (self::HLS_RESOLUTIONS as $label => $spec) {
            $resolutionDir = $hlsAbsoluteDir . '/' . $label;
            if (!is_dir($resolutionDir) && !mkdir($resolutionDir, 0775, true) && !is_dir($resolutionDir)) {
                throw new \RuntimeException(sprintf('Impossible de créer le dossier HLS "%s".', $resolutionDir));
            }

            $renditionPath = $resolutionDir . '/rendition.mp4';
            $format = new X264('aac', 'libx264');
            $format->setKiloBitrate($spec['kiloBitrate']);

            $media = $ffmpeg->open($sourcePath);
            $media->filters()->resize(new Dimension($spec['width'], $spec['height']));
            $media->save($format, $renditionPath);

            $playlistPath = $resolutionDir . '/playlist.m3u8';
            $ffmpeg->getFFMpegDriver()->command([
                '-i', $renditionPath,
                '-c', 'copy',
                '-start_number', '0',
                '-hls_time', (string) self::HLS_SEGMENT_SECONDS,
                '-hls_list_size', '0',
                '-hls_playlist_type', 'vod',
                '-hls_segment_filename', $resolutionDir . '/segment_%03d.ts',
                $playlistPath,
            ]);

            @unlink($renditionPath);

            $variants[] = [
                'label' => $label,
                'bandwidth' => $spec['kiloBitrate'] * 1000,
                'resolution' => $spec['width'] . 'x' . $spec['height'],
            ];

            $this->mediaPipelineLogger->info('Piste HLS générée.', [
                'videoId' => $video->getId(),
                'resolution' => $label,
            ]);
        }

        $masterRelativePath = $hlsRelativeDir . '/master.m3u8';
        $this->writeMasterPlaylist($hlsAbsoluteDir . '/master.m3u8', $variants);

        $video->setHlsPlaylistPath($masterRelativePath);
        $this->entityManager->flush();

        $this->mediaPipelineLogger->info('Playlist maîtresse HLS écrite.', [
            'videoId' => $video->getId(),
            'path' => $masterRelativePath,
        ]);
    }

    /**
     * @param list<array{label: string, bandwidth: int, resolution: string}> $variants
     */
    private function writeMasterPlaylist(string $masterPath, array $variants): void
    {
        $lines = ['#EXTM3U'];

        foreach ($variants as $variant) {
            $lines[] = sprintf('#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%s', $variant['bandwidth'], $variant['resolution']);
            $lines[] = $variant['label'] . '/playlist.m3u8';
        }

        file_put_contents($masterPath, implode("\n", $lines) . "\n");
    }
}
