<?php

namespace App\EventSubscriber;

use App\Entity\VideoFile;
use App\Message\CheckVideoFile;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events as DoctrineEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events;

/**
 * Nouvelle vidéo (formulaire ou import en masse) → vérification de lecture
 * en arrière-plan, pour qu'un fichier illisible soit masqué avant même
 * qu'un visiteur tombe dessus. Envoyée après le flush : un nouveau fichier
 * n'a pas encore d'id au moment de l'upload.
 */
#[AsDoctrineListener(event: DoctrineEvents::postFlush)]
class VideoFileCheckSubscriber implements EventSubscriberInterface
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

        foreach ($pending as $file) {
            if ($file->getId() !== null) {
                $this->bus->dispatch(new CheckVideoFile($file->getId()));
            }
        }
    }
}
