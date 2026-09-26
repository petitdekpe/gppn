<?php

namespace App\Service;

use App\Entity\Video;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class VideoCoverUrlResolver
{
    public function __construct(
        #[Autowire(service: 'video_cover.storage')]
        private readonly FilesystemOperator $storage,
    ) {
    }

    public function resolve(Video $video): ?string
    {
        $fileName = $video->getCoverImageName();

        if ($fileName === null) {
            return null;
        }

        return $this->storage->publicUrl($fileName);
    }
}
