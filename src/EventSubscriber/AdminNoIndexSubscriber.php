<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Interdit l'indexation de l'administration par les moteurs de recherche.
 * La balise <meta name="robots"> des gabarits admin ne couvre que le HTML :
 * l'en-tête X-Robots-Tag s'applique aussi aux redirections (vers la page de
 * connexion notamment), aux téléchargements et aux réponses JSON.
 *
 * Volontairement pas de « Disallow: /admin » dans un robots.txt : un robot
 * qui n'a pas le droit d'explorer une URL ne voit jamais le noindex et peut
 * quand même l'indexer à partir des liens qui y pointent.
 */
final class AdminNoIndexSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if ($path !== '/admin' && !str_starts_with($path, '/admin/')) {
            return;
        }

        $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow');
    }
}
