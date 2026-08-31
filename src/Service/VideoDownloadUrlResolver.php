<?php

namespace App\Service;

use App\Entity\Video;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class VideoDownloadUrlResolver
{
    public function __construct(
        #[Autowire(service: 'download.storage')]
        private readonly FilesystemOperator $storage,
    ) {
    }

    public function resolve(Video $video): ?string
    {
        $fileName = $video->getDownloadFileName();

        if ($fileName === null) {
            return null;
        }

        return $this->storage->publicUrl($fileName);
    }
}
