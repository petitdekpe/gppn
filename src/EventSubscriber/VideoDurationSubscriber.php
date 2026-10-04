<?php

namespace App\EventSubscriber;

use App\Entity\VideoFile;
use App\Message\UpdateVideoDuration;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events as DoctrineEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events;

/**
 * Nouvelle vidéo ou nouvel audio (formulaire ou import en masse) → durée
 * du contenu relue dans ses fichiers, en arrière-plan. Envoyée après le
 * flush : un contenu tout juste créé n'a pas encore d'id au moment de l'upload.
 */
#[AsDoctrineListener(event: DoctrineEvents::postFlush)]
class VideoDurationSubscriber implements EventSubscriberInterface
{
    /** @var VideoFile[] */
    private array $pending = [];

    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [Events::POST_UPLOAD => 'onPostUpload'];
    }

    public function onPostUpload(Event $event): void
    {
        $file = $event->getObject();
        if ($file instanceof VideoFile && ($file->getType()?->isVideo() || $file->getType()?->isAudio())) {
            $this->pending[spl_object_id($file)] = $file;
        }
    }

    public function postFlush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        $queued = [];
        foreach ($pending as $file) {
            $videoId = $file->getVideo()?->getId();
            if ($videoId === null || isset($queued[$videoId])) {
                continue;
            }
            $this->bus->dispatch(new UpdateVideoDuration($videoId));
            $queued[$videoId] = true;
        }
    }
}
