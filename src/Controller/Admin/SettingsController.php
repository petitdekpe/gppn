<?php

namespace App\Controller\Admin;

use App\Form\Admin\SettingsType;
use App\Service\AppSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/parametres')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class SettingsController extends AbstractController
{
    #[Route('', name: 'admin_settings')]
    public function index(
        Request $request,
        AppSettings $settings,
        #[Autowire('%env(MAILER_DSN)%')] string $mailerDsn,
    ): Response {
        $form = $this->createForm(SettingsType::class, [
            'otpEnabled' => $settings->isOtpEnabled(),
            'enabledFormats' => $settings->getEnabledFormats(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $settings->setOtpEnabled($data['otpEnabled']);
            $settings->setEnabledFormats($data['enabledFormats']);

            $this->addFlash('success', 'Paramètres enregistrés.');

            return $this->redirectToRoute('admin_settings');
        }

        return $this->render('admin/settings/index.html.twig', [
            'form' => $form,
            // Avec le transport « null », aucun e-mail ne part : activer l'OTP
            // bloquerait toute connexion hors environnement de développement.
            'mailerDisabled' => str_starts_with($mailerDsn, 'null://'),
        ]);
    }
}
