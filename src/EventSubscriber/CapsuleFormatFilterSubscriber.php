<?php

namespace App\EventSubscriber;

use App\Doctrine\Filter\CapsuleFormatFilter;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Active le filtre des types de contenus sur le site public uniquement :
 * l'admin doit pouvoir gérer tous les fichiers, même d'un type désactivé.
 */
final class CapsuleFormatFilterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AppSettings $settings,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Avant le firewall (priorité 8) et toute requête sur les contenus.
        return [KernelEvents::REQUEST => ['onKernelRequest', 16]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || preg_match('#^/(admin|_profiler|_wdt)(/|$)#', $event->getRequest()->getPathInfo())) {
            return;
        }

        $disabledTypes = $this->settings->getDisabledFileTypes();
        if ($disabledTypes === []) {
            return;
        }

        /** @var CapsuleFormatFilter $filter */
        $filter = $this->entityManager->getFilters()->enable(CapsuleFormatFilter::NAME);
        $filter->setDisabledTypes($disabledTypes);
    }
}
