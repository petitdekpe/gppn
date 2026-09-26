<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\AppSettings;
use App\Service\OtpManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Connexion en deux temps quand l'OTP est activé dans les Paramètres : le
 * mot de passe ouvre la session, mais l'administration reste fermée tant
 * que le code reçu par e-mail n'a pas été saisi (voir
 * SecurityController::verifyOtp).
 */
final class LoginOtpSubscriber implements EventSubscriberInterface
{
    public const PENDING_KEY = 'otp.login_pending';

    /** Chemins accessibles pendant l'attente du code. */
    private const ALLOWED_PATHS = ['/admin/login', '/admin/logout', '/admin/verification', '/admin/verification/renvoyer'];

    public function __construct(
        private readonly AppSettings $settings,
        private readonly OtpManager $otpManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            // Après le firewall (priorité 8), qui restaure la session de l'utilisateur.
            KernelEvents::REQUEST => ['onKernelRequest', 0],
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User || !$this->settings->isOtpEnabled()) {
            return;
        }

        $event->getRequest()->getSession()->set(self::PENDING_KEY, true);
        $this->otpManager->send($user, OtpManager::PURPOSE_LOGIN);
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_login_otp')));
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }

        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/admin') || \in_array(rtrim($path, '/'), self::ALLOWED_PATHS, true)) {
            return;
        }

        if ($request->getSession()->get(self::PENDING_KEY) === true) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_login_otp')));
        }
    }
}
