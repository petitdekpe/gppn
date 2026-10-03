<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\EventSubscriber\LoginOtpSubscriber;
use App\Form\Admin\OtpCodeType;
use App\Service\OtpManager;
use App\Service\OtpResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class SecurityController extends AbstractController
{
    use TargetPathTrait;

    #[Route('/admin/login', name: 'admin_login')]
    public function login(AuthenticationUtils $authenticationUtils, Request $request): Response
    {
        // « Espace médias : se connecter » depuis le site : retour à la page
        // d'origine après connexion. Chemin interne uniquement (pas de
        // redirection vers un autre site).
        $back = $request->query->getString('retour');
        if (self::isSafeReturnPath($back)) {
            $this->saveTargetPath($request->getSession(), 'main', $back);
        }

        return $this->render('admin/security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Seconde étape de la connexion quand l'OTP est activé (voir LoginOtpSubscriber).
     */
    #[Route('/admin/verification', name: 'admin_login_otp')]
    public function verifyOtp(Request $request, OtpManager $otpManager, Security $security): Response
    {
        $session = $request->getSession();
        if ($session->get(LoginOtpSubscriber::PENDING_KEY) !== true) {
            return $this->getUser() !== null && !$this->isGranted('ROLE_ADMIN') ? $this->redirectToRoute('app_media_space') : $this->redirectToRoute('admin_dashboard');
        }

        $form = $this->createForm(OtpCodeType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $result = $otpManager->verify(OtpManager::PURPOSE_LOGIN, $form->get('code')->getData());

            if ($result === OtpResult::VALID) {
                $session->remove(LoginOtpSubscriber::PENDING_KEY);

                // Page d'origine (média venu du site), sinon le tableau de bord ;
                // un compte Média n'y a pas accès : il va à l'espace média.
                $target = $this->getTargetPath($session, 'main');
                if ($target !== null) {
                    $this->removeTargetPath($session, 'main');

                    return $this->redirect($target);
                }

                return $this->isGranted('ROLE_ADMIN') ? $this->redirectToRoute('admin_dashboard') : $this->redirectToRoute('app_media_space');
            }

            if ($result === OtpResult::TOO_MANY_ATTEMPTS) {
                $security->logout(false);
                $this->addFlash('error', 'Trop d’essais incorrects : reconnectez-vous pour recevoir un nouveau code.');

                return $this->redirectToRoute('admin_login');
            }

            $this->addFlash('error', $result->getMessage());

            return $this->redirectToRoute('admin_login_otp');
        }

        return $this->render('admin/security/otp.html.twig', [
            'form' => $form,
            'email' => $this->getUser()?->getUserIdentifier(),
            'resendPath' => 'admin_login_otp_resend',
            'resendWait' => $otpManager->resendWait(OtpManager::PURPOSE_LOGIN),
        ]);
    }

    #[Route('/admin/verification/renvoyer', name: 'admin_login_otp_resend', methods: ['POST'])]
    public function resendOtp(Request $request, OtpManager $otpManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || $request->getSession()->get(LoginOtpSubscriber::PENDING_KEY) !== true) {
            return $this->redirectToRoute('admin_login');
        }

        if ($this->isCsrfTokenValid('otp-resend', $request->request->getString('_token')) && $otpManager->resendWait(OtpManager::PURPOSE_LOGIN) === 0) {
            $otpManager->send($user, OtpManager::PURPOSE_LOGIN);
            $this->addFlash('success', 'Un nouveau code vous a été envoyé.');
        }

        return $this->redirectToRoute('admin_login_otp');
    }

    /**
     * Chemin de ce site uniquement : « //autre-site » ou « /\autre-site »
     * seraient compris par le navigateur comme une autre adresse.
     */
    public static function isSafeReturnPath(string $path): bool
    {
        return str_starts_with($path, '/')
            && !str_starts_with($path, '//')
            && !str_starts_with($path, '/\\')
            && !str_starts_with($path, '/admin/login');
    }

    #[Route('/admin/logout', name: 'admin_logout')]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode est interceptée par la clé "logout" du firewall.');
    }
}
