<?php

namespace App\EventSubscriber;

use App\Entity\VideoFile;
use App\Enum\WebmStatus;
use App\Message\TranscodeToWebm;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events as DoctrineEvents;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events;

/**
 * Tient la version WebM de lecture en phase avec le fichier d'origine :
 * - nouvel upload d'une vidéo TV/Mobile → conversion mise en file, envoyée
 *   après le flush (l'id n'existe pas encore pour un nouveau fichier) ;
 * - fichier d'origine supprimé ou remplacé → ancienne version WebM effacée.
 */
#[AsDoctrineListener(event: DoctrineEvents::postFlush)]
class WebmTranscodeSubscriber implements EventSubscriberInterface
{
    /** @var VideoFile[] */
    private array $pending = [];

    public function __construct(
        private readonly MessageBusInterface $bus,
        #[Autowire(service: 'stream.storage')]
        private readonly FilesystemOperator $streamStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Events::POST_UPLOAD => 'onPostUpload',
            Events::POST_REMOVE => 'onPostRemove',
        ];
    }

    public function onPostUpload(Event $event): void
    {
        $file = $event->getObject();
        if (!$file instanceof VideoFile || !$file->getType()?->hasWebmPlayback()) {
            return;
        }

        $file->setWebmStatus(WebmStatus::PENDING);
        $this->pending[spl_object_id($file)] = $file;
    }

    public function onPostRemove(Event $event): void
    {
        $file = $event->getObject();
        if (!$file instanceof VideoFile || $file->getWebmFileName() === null) {
            return;
        }

        $this->streamStorage->delete($file->getWebmFileName());
        $file->setWebmFileName(null)->setWebmFileSize(null);

        // En cas de remplacement, le nouvel upload fixe lui-même le statut.
        if (!$file->getFile() instanceof UploadedFile) {
            $file->setWebmStatus(null);
        }
    }

    public function postFlush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $file) {
            if ($file->getId() !== null && $file->getWebmStatus() === WebmStatus::PENDING) {
                $this->bus->dispatch(new TranscodeToWebm($file->getId()));
            }
        }
    }
}
