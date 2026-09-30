<?php

namespace App\EventSubscriber;

use App\Entity\VideoFile;
use App\Enum\VideoFileType;
use App\Message\GenerateVideoCover;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events as DoctrineEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events;

/**
 * Nouvelle vidéo TV (formulaire ou import en masse) → couverture tirée de
 * cette vidéo, en arrière-plan. Envoyée après le flush : un contenu tout
 * juste créé n'a pas encore d'id au moment de l'upload.
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
        if ($file instanceof VideoFile && $file->getType() === VideoFileType::MP4_1080P) {
            $this->pending[spl_object_id($file)] = $file;
        }
    }

    public function postFlush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $file) {
            if ($file->getVideo()?->getId() !== null) {
                $this->bus->dispatch(new GenerateVideoCover($file->getVideo()->getId()));
            }
        }
    }
}
