<?php

namespace App\Controller;

use App\Controller\Admin\SecurityController;
use App\Entity\User;
use App\Form\PressRegistrationType;
use App\Service\MediaAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Espace presse : connexion et inscription des médias, demandées pour
 * l'espace média et les téléchargements groupés (archives, lots). L'inscription recueille
 * l'adresse e-mail et le numéro de téléphone.
 *
 * La connexion passe par le même mécanisme que l'administration (formulaire
 * envoyé à admin_login, code de vérification par e-mail) ; un compte « Média »
 * n'a pas pour autant accès au back-office.
 */
class PressController extends AbstractController
{
    use TargetPathTrait;

    #[Route('/espace-presse/connexion', name: 'app_press_login')]
    public function login(Request $request, AuthenticationUtils $authenticationUtils, MediaAccess $mediaAccess): Response
    {
        $back = $this->backPath($request);
        if ($mediaAccess->isMedia()) {
            return $this->redirect($back);
        }
        // Retour après connexion (et après le code de vérification).
        $this->saveTargetPath($request->getSession(), 'main', $back);

        return $this->render('press/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
            'back' => $back,
        ]);
    }

    #[Route('/espace-presse/inscription', name: 'app_press_register')]
    public function register(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher, Security $security, MediaAccess $mediaAccess): Response
    {
        $back = $this->backPath($request);
        if ($mediaAccess->isMedia()) {
            return $this->redirect($back);
        }

        $user = new User();
        $form = $this->createForm(PressRegistrationType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user
                ->setEmail(mb_strtolower(trim($user->getEmail())))
                ->setRoles([User::ROLE_MEDIA])
                ->setRegisteredAt(new \DateTimeImmutable())
                ->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $entityManager->persist($user);
            $entityManager->flush();

            $this->saveTargetPath($request->getSession(), 'main', $back);
            $this->addFlash('success', 'Votre compte presse est créé.');

            // Connexion directe ; avec la vérification par e-mail activée,
            // le code est envoyé avant d'accéder aux téléchargements.
            return $security->login($user, 'form_login', 'main') ?? $this->redirect($back);
        }

        return $this->render('press/register.html.twig', [
            'form' => $form,
            'back' => $back,
        ]);
    }

    /** Page à retrouver après connexion : celle d'où vient le média, sinon l'espace média. */
    private function backPath(Request $request): string
    {
        $back = $request->query->getString('retour');

        return SecurityController::isSafeReturnPath($back) && !str_starts_with($back, '/espace-presse')
            ? $back
            : $this->generateUrl('app_media_space');
    }
}
