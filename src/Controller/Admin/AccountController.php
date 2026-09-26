<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\Admin\ChangePasswordType;
use App\Form\Admin\OtpCodeType;
use App\Service\AppSettings;
use App\Service\OtpManager;
use App\Service\OtpResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Compte de l'utilisateur connecté, quel que soit son rôle.
 */
#[Route('/admin/mon-compte')]
#[IsGranted('ROLE_ADMIN')]
class AccountController extends AbstractController
{
    /**
     * Empreinte du nouveau mot de passe en attente de confirmation par OTP :
     * le mot de passe en clair n'est jamais gardé en session.
     */
    private const PENDING_PASSWORD_KEY = 'account.pending_password_hash';

    #[Route('/mot-de-passe', name: 'admin_account_password')]
    public function changePassword(
        Request $request,
        AppSettings $settings,
        OtpManager $otpManager,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getAccountUser();
        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $hash = $passwordHasher->hashPassword($user, $form->get('newPassword')->getData());

            if (!$settings->isOtpEnabled()) {
                $user->setPassword($hash);
                $entityManager->flush();
                $this->addFlash('success', 'Mot de passe modifié.');

                return $this->redirectToRoute('admin_account_password');
            }

            $request->getSession()->set(self::PENDING_PASSWORD_KEY, $hash);
            $otpManager->send($user, OtpManager::PURPOSE_PASSWORD);

            return $this->redirectToRoute('admin_account_password_verify');
        }

        return $this->render('admin/account/password.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/mot-de-passe/verification', name: 'admin_account_password_verify')]
    public function verifyPasswordChange(Request $request, OtpManager $otpManager, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getAccountUser();
        $session = $request->getSession();
        $pendingHash = $session->get(self::PENDING_PASSWORD_KEY);
        if (!\is_string($pendingHash)) {
            return $this->redirectToRoute('admin_account_password');
        }

        $form = $this->createForm(OtpCodeType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $result = $otpManager->verify(OtpManager::PURPOSE_PASSWORD, $form->get('code')->getData());

            if ($result === OtpResult::VALID) {
                $session->remove(self::PENDING_PASSWORD_KEY);
                $user->setPassword($pendingHash);
                $entityManager->flush();
                $this->addFlash('success', 'Mot de passe modifié.');

                return $this->redirectToRoute('admin_account_password');
            }

            if ($result === OtpResult::TOO_MANY_ATTEMPTS) {
                $session->remove(self::PENDING_PASSWORD_KEY);
                $this->addFlash('error', 'Trop d’essais incorrects : le mot de passe n’a pas été modifié. Recommencez.');

                return $this->redirectToRoute('admin_account_password');
            }

            $this->addFlash('error', $result->getMessage());

            return $this->redirectToRoute('admin_account_password_verify');
        }

        return $this->render('admin/account/password_verify.html.twig', [
            'form' => $form,
            'email' => $user->getEmail(),
            'resendPath' => 'admin_account_password_resend',
            'resendWait' => $otpManager->resendWait(OtpManager::PURPOSE_PASSWORD),
        ]);
    }

    #[Route('/mot-de-passe/verification/renvoyer', name: 'admin_account_password_resend', methods: ['POST'])]
    public function resendPasswordCode(Request $request, OtpManager $otpManager): Response
    {
        if (!\is_string($request->getSession()->get(self::PENDING_PASSWORD_KEY))) {
            return $this->redirectToRoute('admin_account_password');
        }

        if ($this->isCsrfTokenValid('otp-resend', $request->request->getString('_token')) && $otpManager->resendWait(OtpManager::PURPOSE_PASSWORD) === 0) {
            $otpManager->send($this->getAccountUser(), OtpManager::PURPOSE_PASSWORD);
            $this->addFlash('success', 'Un nouveau code vous a été envoyé.');
        }

        return $this->redirectToRoute('admin_account_password_verify');
    }

    private function getAccountUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
