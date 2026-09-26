<?php

namespace App\Service;

use App\Entity\VideoFile;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class VideoDownloadUrlResolver
{
    public function __construct(
        #[Autowire(service: 'download.storage')]
        private readonly FilesystemOperator $storage,
        #[Autowire(service: 'stream.storage')]
        private readonly FilesystemOperator $streamStorage,
    ) {
    }

    public function resolve(VideoFile $file): ?string
    {
        $fileName = $file->getFileName();

        if ($fileName === null) {
            return null;
        }

        return $this->storage->publicUrl($fileName);
    }

    /**
     * URL de la version WebM de lecture, null tant qu'elle n'est pas prête.
     */
    public function resolveWebm(VideoFile $file): ?string
    {
        if (!$file->hasPlayableWebm()) {
            return null;
        }

        return $this->streamStorage->publicUrl($file->getWebmFileName());
    }
}
