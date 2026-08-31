<?php

namespace App\EventSubscriber;

use App\Entity\Video;
use App\Message\ProcessVideoMessage;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events as DoctrineEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Vich\UploaderBundle\Event\Event as VichEvent;
use Vich\UploaderBundle\Event\Events as VichEvents;

/**
 * Déclenche le pipeline de traitement (hash, vignette, HLS) après l'upload
 * d'un fichier vidéo source.
 *
 * Vich::POST_UPLOAD se déclenche pendant prePersist/preUpdate, donc avant que
 * Doctrine n'assigne l'ID d'une capsule neuve — alors que ProcessVideoMessage
 * a justement besoin de cet ID. On mémorise donc ici les vidéos concernées et
 * on ne dispatche réellement le message qu'au postFlush qui suit dans le même
 * cycle, une fois l'ID garanti disponible (création comme mise à jour).
 */
#[AsDoctrineListener(event: DoctrineEvents::postFlush)]
final class VichUploadSubscriber implements EventSubscriberInterface
{
    private const MAPPING_NAME = 'video_source';

    /** @var list<Video> */
    private array $pendingVideos = [];

    public function __construct(private readonly MessageBusInterface $messageBus)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            VichEvents::POST_UPLOAD => 'onVichPostUpload',
        ];
    }

    public function onVichPostUpload(VichEvent $event): void
    {
        if ($event->getMapping()->getMappingName() !== self::MAPPING_NAME) {
            return;
        }

        $video = $event->getObject();

        if ($video instanceof Video) {
            $this->pendingVideos[] = $video;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pendingVideos === []) {
            return;
        }

        $videos = $this->pendingVideos;
        $this->pendingVideos = [];

        foreach ($videos as $video) {
            if ($video->getId() === null) {
                continue;
            }

            $this->messageBus->dispatch(new ProcessVideoMessage($video->getId()));
        }
    }
}
