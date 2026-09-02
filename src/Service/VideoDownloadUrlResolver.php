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
}
