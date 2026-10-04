<?php

namespace App\Service;

use App\Entity\SitePage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Pages de texte modifiables depuis l'admin (menu « Pages »). Chaque page a
 * un texte par défaut, en Markdown, dans templates/site_page/defaults/ :
 * affiché tant que la page n'a jamais été enregistrée, et rétablissable
 * depuis l'admin. Les passages entre crochets [à compléter …] attendent les
 * informations propres au programme (responsable du traitement, hébergeur…).
 */
class SitePages
{
    public const PRIVACY = 'confidentialite';
    public const LEGAL_NOTICE = 'mentions-legales';
    public const CONTACT = 'contact';

    /** @var array<string, array{title: string, route: string, help: string}> */
    public const DEFINITIONS = [
        self::PRIVACY => [
            'title' => 'Politique de confidentialité',
            'route' => 'app_privacy',
            'help' => 'Données personnelles traitées par le site, conformément au Code du numérique et aux exigences de l’APDP.',
        ],
        self::LEGAL_NOTICE => [
            'title' => 'Mentions légales',
            'route' => 'app_legal_notice',
            'help' => 'Éditeur, directeur de la publication, hébergeur, propriété intellectuelle et droit applicable.',
        ],
        self::CONTACT => [
            'title' => 'Nous contacter',
            'route' => 'app_contact',
            'help' => 'Coordonnées affichées à côté du formulaire de contact.',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%/templates/site_page/defaults')]
        private readonly string $defaultsDir,
    ) {
    }

    /** Page enregistrée, ou à défaut une page non enregistrée portant le texte par défaut. */
    public function get(string $slug): SitePage
    {
        if (!isset(self::DEFINITIONS[$slug])) {
            throw new \InvalidArgumentException(sprintf('Page inconnue : %s', $slug));
        }

        return $this->entityManager->find(SitePage::class, $slug)
            ?? new SitePage($slug, self::DEFINITIONS[$slug]['title'], $this->defaultContent($slug));
    }

    public function defaultContent(string $slug): string
    {
        return (string) file_get_contents(sprintf('%s/%s.md', $this->defaultsDir, $slug));
    }
}
