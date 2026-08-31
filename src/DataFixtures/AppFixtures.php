<?php

namespace App\DataFixtures;

use App\Entity\Language;
use App\Entity\Speaker;
use App\Entity\Thematic;
use App\Entity\User;
use App\Entity\Video;
use App\Enum\CapsuleFormat;
use App\Enum\VideoStatus;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

class AppFixtures extends Fixture
{
    /**
     * Identifiants de démonstration pour l'environnement local — à changer avant tout
     * déploiement réel (voir README / commande app:admin:create-user en production).
     * Un compte par rôle pour permettre de tester les permissions du back-office.
     */
    private const DEMO_PASSWORD = 'ChangeMoi123!';

    /**
     * Rotation des formats de capsules pour la démo, à dominante vidéo.
     *
     * @var list<CapsuleFormat>
     */
    private const FORMAT_ROTATION = [
        CapsuleFormat::VIDEO, CapsuleFormat::VIDEO, CapsuleFormat::VIDEO, CapsuleFormat::AUDIO,
        CapsuleFormat::VIDEO, CapsuleFormat::VIDEO, CapsuleFormat::PDF, CapsuleFormat::VIDEO,
        CapsuleFormat::VIDEO, CapsuleFormat::IMAGE,
    ];

    private const DEMO_USERS = [
        ['superadmin@gppn.bj', User::ROLE_SUPER_ADMIN],
        ['editeur@gppn.bj', User::ROLE_EDITEUR],
        ['moderateur@gppn.bj', User::ROLE_MODERATEUR],
        ['presse@gppn.bj', User::ROLE_LECTEUR_PRESSE],
    ];

    public function __construct(private readonly UserPasswordHasherInterface $passwordHasher)
    {
    }

    private const LANGUAGES = [
        'Fon', 'Yoruba', 'Dendi', 'Goun', 'Ditamari', 'Baatonou', 'Adja',
        'Mahi', 'Idatcha', 'Tori', 'Kotafon', 'Fulfulde', 'Sahoué', 'Waama',
    ];

    private const SPEAKERS = [
        ['Aïchatou Bello', 'Directrice de l’ANIP'],
        ['Comlan Adjovi', 'Chef service à l’état civil'],
        ['Rachidatou Salami', 'Médecin de santé publique'],
        ['Bertin Kponou', 'Conseiller agricole'],
        ['Fatouma Séro', 'Chargée de projet transformation numérique'],
        ['Damien Houngbédji', 'Inspecteur de l’enseignement primaire'],
        ['Léontine Amoussou', 'Ingénieure à la société d’électricité'],
        ['Moussa Guédou', 'Responsable de l’ANPE'],
    ];

    private const LEARNING_POINTS = [
        'Les pièces exactes à réunir avant de vous déplacer.',
        'Le coût officiel et les délais réellement constatés.',
        'L’interlocuteur à contacter en cas de blocage.',
        'Les erreurs les plus fréquentes signalées par les usagers.',
    ];

    private const THEMATICS = [
        [
            'name' => 'État civil',
            'color' => '#1F7A54',
            'videos' => [
                ['Obtenir sa carte d’identité biométrique : les 4 étapes', 'Où aller, quels papiers apporter, combien ça coûte et en combien de temps la carte est prête. Tout est expliqué pas à pas, avec les cas particuliers des personnes nées hors du Bénin.'],
                ['Déclarer une naissance à l’état civil : le guide complet', 'Les délais à respecter, les pièces à fournir et la marche à suivre pour déclarer la naissance d’un enfant dans sa commune.'],
                ['Refaire un acte de naissance perdu ou endommagé', 'La procédure de reconstitution d’un acte de naissance, les délais moyens et les frais à prévoir.'],
            ],
        ],
        [
            'name' => 'Santé',
            'color' => '#C9932A',
            'videos' => [
                ['Prise en charge des urgences sanitaires', 'Le circuit d’une urgence médicale dans un centre de santé public, du premier accueil à l’orientation vers un plateau technique adapté.'],
                ['S’inscrire à l’Assurance pour le Renforcement du Capital Humain', 'Qui peut bénéficier de l’ARCH, comment s’inscrire et quelles prestations sont couvertes.'],
                ['Vaccination des enfants : le calendrier à respecter', 'Les rendez-vous vaccinaux essentiels de la naissance à 5 ans et où se faire vacciner gratuitement.'],
            ],
        ],
        [
            'name' => 'Agriculture',
            'color' => '#2F6F93',
            'videos' => [
                ['Commercialisation des pesticides non autorisés', 'Les risques encourus, comment reconnaître un produit homologué et vers qui se tourner en cas de doute.'],
                ['Accéder aux engrais subventionnés pour la campagne agricole', 'Les conditions d’éligibilité et les points de retrait des intrants subventionnés par l’État.'],
                ['Assurance agricole : se protéger contre les aléas climatiques', 'Le fonctionnement du dispositif d’assurance agricole et les cultures couvertes.'],
            ],
        ],
        [
            'name' => 'Numérique',
            'color' => '#8A4B8F',
            'videos' => [
                ['Créer son compte sur le portail service-public.bj', 'Les étapes pour créer et sécuriser son compte citoyen afin d’accéder aux démarches en ligne.'],
                ['Signer un document administratif en ligne', 'Le fonctionnement de la signature électronique et sa valeur légale pour vos démarches.'],
                ['Protéger ses données personnelles sur internet', 'Les bons réflexes pour éviter le vol d’identité numérique et les arnaques en ligne.'],
            ],
        ],
        [
            'name' => 'Éducation',
            'color' => '#4C7A2E',
            'videos' => [
                ['Inscrire son enfant à l’école primaire publique', 'Les pièces à fournir, les périodes d’inscription et les démarches auprès de l’école de secteur.'],
                ['Bourses d’études : qui peut en bénéficier ?', 'Les critères d’attribution des bourses nationales et le calendrier de candidature.'],
                ['Cantines scolaires : comment ça marche', 'Le fonctionnement du programme national de cantines scolaires et les écoles concernées.'],
            ],
        ],
        [
            'name' => 'Énergie',
            'color' => '#B3541F',
            'videos' => [
                ['Se raccorder au réseau électrique national', 'La procédure de demande de branchement, les coûts indicatifs et les délais moyens.'],
                ['Kits solaires subventionnés pour les zones rurales', 'Comment bénéficier d’un kit solaire à prix réduit dans les localités non couvertes par le réseau.'],
                ['Signaler une panne d’éclairage public', 'Le circuit pour signaler une panne et suivre son traitement par les services concernés.'],
            ],
        ],
        [
            'name' => 'Eau & assainissement',
            'color' => '#1F7A94',
            'videos' => [
                ['Obtenir un raccordement au réseau d’eau potable', 'Les démarches auprès de la société de gestion de l’eau et les frais de branchement.'],
                ['Gérer ses déchets ménagers dans sa commune', 'Les jours de collecte, le tri de base et les points de dépôt disponibles.'],
                ['Latrines familiales : le programme d’appui', 'Les conditions pour bénéficier d’un appui à la construction de latrines aux normes.'],
            ],
        ],
        [
            'name' => 'Emploi & entrepreneuriat',
            'color' => '#9C3550',
            'videos' => [
                ['S’inscrire à l’Agence Nationale Pour l’Emploi', 'Comment créer son profil demandeur d’emploi et accéder aux offres et formations disponibles.'],
                ['Créer son entreprise en ligne en 48h', 'Le parcours simplifié de création d’entreprise via le guichet unique en ligne.'],
                ['Financer son projet avec le Fonds National de la Microfinance', 'Les types de financement disponibles et les conditions d’éligibilité pour les porteurs de projet.'],
            ],
        ],
        [
            'name' => 'Protection sociale',
            'color' => '#2D5B8A',
            'videos' => [
                ['Bénéficier des cantines et filets sociaux', 'Le fonctionnement des programmes de filets sociaux destinés aux ménages en situation de vulnérabilité.'],
                ['Carte d’égalité des chances pour les personnes handicapées', 'Les avantages liés à la carte et la procédure pour en faire la demande.'],
                ['Soutien aux ménages vulnérables : les critères d’éligibilité', 'Comment un ménage est identifié comme éligible aux programmes sociaux de l’État.'],
            ],
        ],
        [
            'name' => 'Transport',
            'color' => '#7A6A1F',
            'videos' => [
                ['Obtenir son permis de conduire biométrique', 'Les étapes de l’examen, les pièces à fournir et les délais de délivrance du nouveau permis.'],
                ['Immatriculer son véhicule en ligne', 'La procédure d’immatriculation dématérialisée et les documents nécessaires.'],
                ['Sécurité routière : les nouvelles règles', 'Les principales règles de circulation à connaître pour rouler en toute sécurité.'],
            ],
        ],
    ];

    public function load(ObjectManager $manager): void
    {
        $slugger = new AsciiSlugger('fr');

        $languages = [];
        foreach (self::LANGUAGES as $name) {
            $language = (new Language())
                ->setName($name)
                ->setSlug(strtolower($slugger->slug($name)));
            $manager->persist($language);
            $languages[] = $language;
        }

        $speakers = [];
        foreach (self::SPEAKERS as [$fullName, $role]) {
            $speaker = (new Speaker())
                ->setFullName($fullName)
                ->setRole($role);
            $manager->persist($speaker);
            $speakers[] = $speaker;
        }

        $languageIndex = 0;
        $videoIndex = 0;
        $now = new \DateTimeImmutable();

        foreach (self::THEMATICS as $thematicData) {
            $thematic = (new Thematic())
                ->setName($thematicData['name'])
                ->setColorHex($thematicData['color'])
                ->setSlug(strtolower($slugger->slug($thematicData['name'])));
            $manager->persist($thematic);

            foreach ($thematicData['videos'] as $position => [$title, $summary]) {
                $language = $languages[$languageIndex % count($languages)];
                ++$languageIndex;

                $daysAgo = ($videoIndex * 3) % 120;
                $duration = 150 + (($videoIndex * 37) % 270);
                $views = 600 + (($videoIndex * 733) % 24000);

                $video = (new Video())
                    ->setTitle($title)
                    ->setSlug(strtolower($slugger->slug($title)) . '-' . ($videoIndex + 1))
                    ->setSummary($summary)
                    ->setFormat(self::FORMAT_ROTATION[$videoIndex % count(self::FORMAT_ROTATION)])
                    ->setStatus(VideoStatus::PUBLIE)
                    ->setThematic($thematic)
                    ->setLanguage($language)
                    ->setDurationSeconds($duration)
                    ->setViewsCount($views)
                    ->setPublishedAt($now->modify(sprintf('-%d days', $daysAgo)))
                    ->setLearningPoints(implode("\n", self::LEARNING_POINTS))
                    ->setFeatured($position === 0 && $videoIndex % 6 === 0);

                $video->addSpeaker($speakers[$videoIndex % count($speakers)]);
                if ($videoIndex % 3 === 0) {
                    $video->addSpeaker($speakers[($videoIndex + 1) % count($speakers)]);
                }

                $manager->persist($video);
                ++$videoIndex;
            }
        }

        foreach (self::DEMO_USERS as [$email, $role]) {
            $user = new User();
            $user->setEmail($email);
            $user->setRole($role);
            $user->setPassword($this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD));
            $manager->persist($user);
        }

        $manager->flush();
    }
}
