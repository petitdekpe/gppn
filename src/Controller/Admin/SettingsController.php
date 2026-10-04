<?php

namespace App\Controller\Admin;

use App\Form\Admin\SettingsType;
use App\Service\AppSettings;
use App\Service\CoverThumbnailer;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        CoverThumbnailer $thumbnailer,
        #[Autowire(service: 'video_cover.storage')] FilesystemOperator $coverStorage,
        #[Autowire('%env(MAILER_DSN)%')] string $mailerDsn,
    ): Response {
        $form = $this->createForm(SettingsType::class, [
            'otpEnabled' => $settings->isOtpEnabled(),
            'feedbackEnabled' => $settings->isFeedbackEnabled(),
            'enabledFormats' => $settings->getEnabledFormats(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $settings->setOtpEnabled($data['otpEnabled']);
            $settings->setFeedbackEnabled($data['feedbackEnabled']);
            $settings->setEnabledFormats($data['enabledFormats']);

            $previousCover = $settings->getDefaultCover();
            if ($data['defaultCover'] instanceof UploadedFile) {
                // Nom neuf à chaque dépôt : les navigateurs ne gardent pas l'ancienne image en cache.
                $fileName = sprintf('default-%s.%s', bin2hex(random_bytes(6)), $data['defaultCover']->guessExtension() ?? 'jpg');
                $stream = fopen($data['defaultCover']->getPathname(), 'rb');
                try {
                    $coverStorage->writeStream($fileName, $stream);
                } finally {
                    fclose($stream);
                }
                $settings->setDefaultCover($fileName);
                $thumbnailer->generate($fileName);
            } elseif ($data['removeDefaultCover']) {
                $settings->setDefaultCover(null);
            }
            if ($previousCover !== null && $previousCover !== $settings->getDefaultCover()) {
                try {
                    $coverStorage->delete($previousCover);
                    $thumbnailer->delete($previousCover);
                } catch (FilesystemException) {
                    // Fichier orphelin sans conséquence : le paramètre ne le désigne plus.
                }
            }

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
