<?php

namespace App\EventSubscriber;

use App\Entity\VideoFile;
use App\Message\GenerateVideoCover;
use App\Service\VideoCoverGenerator;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events as DoctrineEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events;

/**
 * Nouvelle vidéo TV ou Mobile (formulaire ou import en masse) → couverture
 * tirée de cette vidéo, en arrière-plan, si c'est elle la meilleure source
 * du contenu (une vidéo Mobile ne sert que faute de vidéo TV). Envoyée après
 * le flush : un contenu tout juste créé n'a pas encore d'id au moment de l'upload.
 */
#[AsDoctrineListener(event: DoctrineEvents::postFlush)]
class VideoCoverSubscriber implements EventSubscriberInterface
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
        if ($file instanceof VideoFile && $file->getType()?->isVideo()) {
            $this->pending[spl_object_id($file)] = $file;
        }
    }

    public function postFlush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        $queued = [];
        foreach ($pending as $file) {
            $video = $file->getVideo();
            if ($video?->getId() === null || isset($queued[$video->getId()]) || VideoCoverGenerator::source($video) !== $file) {
                continue;
            }
            $this->bus->dispatch(new GenerateVideoCover($video->getId()));
            $queued[$video->getId()] = true;
        }
    }
}
