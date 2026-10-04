<?php

namespace App\EventSubscriber;

use App\Service\CoverThumbnailer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events;

/**
 * Couverture déposée ou générée → sa vignette WebP pour les cartes ;
 * couverture retirée ou remplacée → la vignette part avec elle.
 */
class CoverThumbnailSubscriber implements EventSubscriberInterface
{
    private const MAPPING = 'video_cover';

    public function __construct(
        private readonly CoverThumbnailer $thumbnailer,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Events::POST_UPLOAD => 'onPostUpload',
            Events::PRE_REMOVE => 'onPreRemove',
        ];
    }

    public function onPostUpload(Event $event): void
    {
        $fileName = $this->fileName($event);
        if ($fileName !== null) {
            $this->thumbnailer->generate($fileName);
        }
    }

    public function onPreRemove(Event $event): void
    {
        $fileName = $this->fileName($event);
        if ($fileName !== null) {
            $this->thumbnailer->delete($fileName);
        }
    }

    private function fileName(Event $event): ?string
    {
        $mapping = $event->getMapping();

        return $mapping->getMappingName() === self::MAPPING ? $mapping->getFileName($event->getObject()) : null;
    }
}
